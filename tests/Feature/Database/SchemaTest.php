<?php

declare(strict_types=1);

use TamasLabs\LaravelExpenses\Support\PackageConfig;
use TamasLabs\LaravelExpenses\Tests\Support\SchemaInspector;

// Spec 03, 3.11. `user_id` follows the host's key: Testbench's users table
// has a `bigint unsigned` id.
const SCHEMA_UUID = 'char(36) ascii_bin';
const SCHEMA_NULLABLE_UUID = 'char(36) null ascii_bin';
const SCHEMA_HASH_KEY = 'char(64) null ascii_bin generated';

/**
 * @return array<string, string>
 */
function schemaSyncColumns(): array
{
    return [
        'created_at' => 'datetime(3)',
        'updated_at' => 'datetime(3)',
        'deleted_at' => 'datetime(3) null',
        'server_seq' => 'bigint unsigned',
        'synced_at' => 'datetime(3)',
    ];
}

/**
 * @return array<string, array<string, string>>
 */
function schemaExpectedTables(): array
{
    $owned = ['id' => SCHEMA_UUID, 'user_id' => 'bigint unsigned'];

    return [
        'sync_sequence' => [
            'id' => 'tinyint unsigned',
            'value' => 'bigint unsigned',
            'pruned_through' => 'bigint unsigned',
        ],
        'currencies' => [
            'id' => SCHEMA_UUID,
            'code' => 'char(3) ascii_bin',
            'name' => 'text',
            'symbol' => 'text',
            'minor_unit' => 'tinyint unsigned',
            ...schemaSyncColumns(),
        ],
        'categories' => [
            ...$owned,
            'name' => 'text',
            'sort_order' => 'int null',
            'color' => 'char(7) null',
            'icon' => 'text null',
            ...schemaSyncColumns(),
            'name_key' => SCHEMA_HASH_KEY,
        ],
        'subcategories' => [
            ...$owned,
            'category_id' => SCHEMA_UUID,
            'name' => 'text',
            'sort_order' => 'int null',
            ...schemaSyncColumns(),
            'name_key' => SCHEMA_HASH_KEY,
        ],
        'payment_methods' => [
            ...$owned,
            'name' => 'text',
            'sort_order' => 'int null',
            'color' => 'char(7) null',
            'icon' => 'text null',
            ...schemaSyncColumns(),
            'name_key' => SCHEMA_HASH_KEY,
        ],
        'expenses' => [
            ...$owned,
            'name' => 'text',
            'type' => 'varchar(16)',
            'amount_minor' => 'bigint unsigned',
            'currency_id' => SCHEMA_UUID,
            'expense_date' => 'date',
            'main_category_id' => SCHEMA_NULLABLE_UUID,
            'sub_category_id' => SCHEMA_NULLABLE_UUID,
            'payment_method_id' => SCHEMA_NULLABLE_UUID,
            'shop_display_name' => 'varchar(80) null',
            'normalized_shop' => 'text null',
            'note' => 'text null',
            'items' => 'longtext',
            ...schemaSyncColumns(),
        ],
        'products' => [
            ...$owned,
            'normalized_name' => 'text',
            'display_name' => 'varchar(80)',
            'main_category_id' => SCHEMA_NULLABLE_UUID,
            'sub_category_id' => SCHEMA_NULLABLE_UUID,
            'default_unit' => 'varchar(20) null',
            'barcode' => 'varchar(13) null ascii_bin',
            'off_data' => 'longtext null',
            'off_category' => 'text null',
            'off_last_synced_at' => 'datetime(3) null',
            'user_note' => 'text null',
            'user_rating' => 'tinyint unsigned null',
            'user_status' => 'varchar(16) null',
            'preferred_shop' => 'text null',
            'user_tags' => 'text',
            'purchase_reminder' => 'tinyint(1)',
            'custom_fields' => 'text',
            ...schemaSyncColumns(),
            'normalized_name_key' => SCHEMA_HASH_KEY,
            'barcode_key' => 'varchar(13) null ascii_bin generated',
        ],
        'product_prices' => [
            ...$owned,
            'product_id' => SCHEMA_UUID,
            'shop_display_name' => 'varchar(80)',
            'normalized_shop' => 'text',
            'unit_price_minor' => 'bigint unsigned',
            'currency_id' => SCHEMA_UUID,
            'quantity' => 'double null',
            'unit' => 'varchar(20) null',
            'observed_at' => 'date',
            'source' => 'varchar(16)',
            'source_expense_id' => SCHEMA_NULLABLE_UUID,
            ...schemaSyncColumns(),
        ],
        'shopping_lists' => [
            ...$owned,
            'name' => 'text',
            'shop' => 'text null',
            'status' => 'varchar(16)',
            'archived_at' => 'datetime(3) null',
            ...schemaSyncColumns(),
        ],
        'shopping_list_items' => [
            ...$owned,
            'shopping_list_id' => SCHEMA_UUID,
            'name' => 'text',
            'normalized_name' => 'text',
            'quantity' => 'double',
            'unit' => 'text null',
            'is_checked' => 'tinyint(1)',
            'sort_order' => 'int',
            ...schemaSyncColumns(),
        ],
        'budget_pockets' => [
            ...$owned,
            'name' => 'text',
            'start_day' => 'tinyint unsigned',
            'budget_amount_minor' => 'bigint unsigned',
            'color' => 'char(7) null',
            ...schemaSyncColumns(),
        ],
        'budget_pocket_categories' => [
            'user_id' => 'bigint unsigned',
            'budget_pocket_id' => SCHEMA_UUID,
            'category_id' => SCHEMA_UUID,
        ],
    ];
}

it('creates every table with the columns of spec 03', function (string $table, array $columns): void {
    expect(SchemaInspector::current()->columns($table))->toBe($columns);
})->with(function (): iterable {
    foreach (schemaExpectedTables() as $table => $columns) {
        yield $table => [$table, $columns];
    }
});

it('adds the profile columns to the host users table', function (): void {
    $columns = SchemaInspector::current()->columns(PackageConfig::usersTable());

    expect($columns)->toMatchArray([
        'uuid' => SCHEMA_UUID,
        'default_currency_id' => SCHEMA_NULLABLE_UUID,
        'registered_at' => 'datetime(3) null',
        'profile_created_at' => 'datetime(3) null',
        'profile_updated_at' => 'datetime(3) null',
        'server_seq' => 'bigint unsigned null',
    ]);
});

it('keys every table as spec 03 says', function (): void {
    $owned = ['categories', 'subcategories', 'payment_methods', 'expenses', 'products', 'product_prices',
        'shopping_lists', 'shopping_list_items', 'budget_pockets'];

    $expected = [
        'budget_pocket_categories PRIMARY(user_id,budget_pocket_id,category_id)',
        'categories UNIQUE(user_id,name_key)',
        'currencies PRIMARY(id)',
        'currencies UNIQUE(code)',
        'currencies UNIQUE(server_seq)',
        'payment_methods UNIQUE(user_id,name_key)',
        'products UNIQUE(user_id,barcode_key)',
        'products UNIQUE(user_id,normalized_name_key)',
        'subcategories UNIQUE(user_id,category_id,name_key)',
        'sync_sequence PRIMARY(id)',
        'users PRIMARY(id)',
        'users UNIQUE(email)',
        'users UNIQUE(server_seq)',
        'users UNIQUE(uuid)',
    ];

    foreach ($owned as $table) {
        $expected[] = "{$table} PRIMARY(user_id,id)";
        $expected[] = "{$table} UNIQUE(server_seq)";
    }

    $packageTables = [...array_keys(schemaExpectedTables()), 'users'];
    $actual = array_values(array_filter(
        SchemaInspector::current()->uniqueKeys(),
        static fn (string $key): bool => in_array(strstr($key, ' ', true), $packageTables, true),
    ));

    expect($actual)->toEqualCanonicalizing($expected);

    foreach ($owned as $table) {
        expect(SchemaInspector::current()->indexes($table))->toContain('user_id,server_seq');
    }
});

it('links the tables with RESTRICT foreign keys, within one user', function (): void {
    $user = 'users(id) RESTRICT/RESTRICT';
    $expected = [
        'users(default_currency_id) -> currencies(id) RESTRICT/RESTRICT',
        'subcategories(user_id,category_id) -> categories(user_id,id) RESTRICT/RESTRICT',
        'expenses(currency_id) -> currencies(id) RESTRICT/RESTRICT',
        'expenses(user_id,main_category_id) -> categories(user_id,id) RESTRICT/RESTRICT',
        'expenses(user_id,sub_category_id) -> subcategories(user_id,id) RESTRICT/RESTRICT',
        'expenses(user_id,payment_method_id) -> payment_methods(user_id,id) RESTRICT/RESTRICT',
        'products(user_id,main_category_id) -> categories(user_id,id) RESTRICT/RESTRICT',
        'products(user_id,sub_category_id) -> subcategories(user_id,id) RESTRICT/RESTRICT',
        'product_prices(user_id,product_id) -> products(user_id,id) RESTRICT/RESTRICT',
        'product_prices(currency_id) -> currencies(id) RESTRICT/RESTRICT',
        'product_prices(user_id,source_expense_id) -> expenses(user_id,id) RESTRICT/RESTRICT',
        'shopping_list_items(user_id,shopping_list_id) -> shopping_lists(user_id,id) RESTRICT/RESTRICT',
        'budget_pocket_categories(user_id,budget_pocket_id) -> budget_pockets(user_id,id) RESTRICT/RESTRICT',
        'budget_pocket_categories(user_id,category_id) -> categories(user_id,id) RESTRICT/RESTRICT',
    ];

    foreach (['categories', 'subcategories', 'payment_methods', 'expenses', 'products', 'product_prices',
        'shopping_lists', 'shopping_list_items', 'budget_pockets', 'budget_pocket_categories'] as $table) {
        $expected[] = "{$table}(user_id) -> {$user}";
    }

    expect(SchemaInspector::current()->foreignKeys())->toEqualCanonicalizing($expected);
});

it('leaves room for the longest table prefix in every index and constraint name', function (): void {
    $longest = max([0, ...array_map(strlen(...), SchemaInspector::current()->identifiers())]);

    expect($longest + PackageConfig::MAX_TABLE_PREFIX_LENGTH)->toBeLessThanOrEqual(64);
});
