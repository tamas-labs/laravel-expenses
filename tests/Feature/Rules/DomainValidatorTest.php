<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use TamasLabs\LaravelExpenses\Database\Currencies;
use TamasLabs\LaravelExpenses\Database\Factories\SyncModelFactory;
use TamasLabs\LaravelExpenses\Mapping\FieldType;
use TamasLabs\LaravelExpenses\Models\Currency;
use TamasLabs\LaravelExpenses\Registry\ResourceDefinition;
use TamasLabs\LaravelExpenses\Registry\ResourceRegistry;
use TamasLabs\LaravelExpenses\Rules\Batch\ReferencesExist;
use TamasLabs\LaravelExpenses\Rules\Batch\UniqueAmongLiving;
use TamasLabs\LaravelExpenses\Rules\DomainValidator;
use TamasLabs\LaravelExpenses\Rules\RecordFields;
use TamasLabs\LaravelExpenses\Rules\RuleContext;
use TamasLabs\LaravelExpenses\Support\PackageConfig;
use TamasLabs\LaravelExpenses\Sync\SyncStore;
use TamasLabs\LaravelExpenses\Tests\Fixtures\User;
use TamasLabs\LaravelExpenses\Tests\Support\Records;
use TamasLabs\LaravelExpenses\Tests\Support\RuleRunner;
use TamasLabs\LaravelExpenses\Tests\TestCase;

// Spec 05, 6 — the rule engine.

const PIVOT = 'budget_pocket_categories';

it('has rules for exactly the pushable resources', function (): void {
    $pushable = array_map(static fn (ResourceDefinition $definition): string => $definition->name, app(ResourceRegistry::class)->pushable());

    expect(app(DomainValidator::class)->resources())->toEqualCanonicalizing($pushable);
});

it('has no rules for what a client cannot push', function (string $resource): void {
    app(DomainValidator::class)->validate($resource, [], new RuleContext(User::factory()->createOne(), app(SyncStore::class)));
})->with(['user', 'currency'])->throws(InvalidArgumentException::class, 'not pushable');

it('is a singleton', function (): void {
    expect(app(DomainValidator::class))->toBe(app(DomainValidator::class));
});

it('returns every issue of every rule a record breaks', function (): void {
    $user = User::factory()->createOne();
    $live = Records::stored('product', $user, ['barcode' => '5998200123456']);
    $missing = SyncModelFactory::uuid();

    $issues = RuleRunner::check($user, 'product', Records::make('product', [
        'normalizedName' => $live->normalizedName,
        'barcode' => '5998200123456',
        'mainCategoryId' => $missing,
    ]));

    expect(array_map(static fn (array $issue): string => "{$issue['path']} {$issue['keyword']}", $issues[0]))->toBe([
        '/mainCategoryId reference',
        '/normalizedName unique',
        '/barcode unique',
    ]);
});

it('returns the issues of the reference and the currency rules together', function (): void {
    $user = User::factory()->createOne();
    Currency::query()->whereKey(Currencies::USD)->update(['deleted_at' => '2026-09-01 00:00:00.000']);

    $issues = RuleRunner::check($user, 'expense', Records::make('expense', [
        'currencyId' => Currencies::USD,
        'paymentMethodId' => SyncModelFactory::uuid(),
    ]));

    expect(array_column($issues[0], 'keyword'))->toBe(['reference', 'retired']);
});

it('runs the batch rules only on the records the record rules passed', function (): void {
    $user = User::factory()->createOne();
    $broken = Records::make('expense', ['amountMinor' => 1, 'mainCategoryId' => SyncModelFactory::uuid()]);
    $fine = Records::make('expense', ['mainCategoryId' => SyncModelFactory::uuid()]);

    $issues = RuleRunner::check($user, 'expense', $broken, $fine);

    expect(array_column($issues[0], 'keyword'))->toBe(['amountSum'])
        ->and(array_column($issues[1], 'keyword'))->toBe(['reference']);
});

it('keeps the indexes of the records it gets', function (): void {
    $user = User::factory()->createOne();
    $context = new RuleContext($user, app(SyncStore::class));
    $context->setExisting('category', []);

    $issues = app(DomainValidator::class)->validate('category', [3 => Records::make('category'), 7 => Records::make('category')], $context);

    expect($issues)->toBe([3 => [], 7 => []]);
});

it('needs the server\'s rows of the records to judge a currency change', function (): void {
    app(DomainValidator::class)->validate('expense', [Records::make('expense')], new RuleContext(User::factory()->createOne(), app(SyncStore::class)));
})->throws(LogicException::class, 'no server rows for the expense records');

/**
 * A push of 9 × $perResource records. Every second record refers to parents
 * of the same push, the others to parents of their own stored earlier, so
 * every lookup gets many ids.
 *
 * @return array<string, list<stdClass>>
 */
function largePush(User $user, int $perResource): array
{
    /** @var array<string, list<stdClass>> $push */
    $push = [];
    for ($i = 0; $i < $perResource; $i++) {
        $push['category'][] = Records::make('category');
        $push['payment-method'][] = Records::make('payment-method');
        $push['shopping-list'][] = Records::make('shopping-list');
        $push['subcategory'][] = Records::make('subcategory', ['categoryId' => RecordFields::id($push['category'][$i])]);
        $push['expense'][] = Records::make('expense');
        $push['product'][] = Records::make('product', ['barcode' => sprintf('59982%08d', $i)]);

        $parent = largePushParents($user, $push, $i);

        $push['subcategory'][$i]->categoryId = $parent['category'];
        $push['expense'][$i]->mainCategoryId = $parent['category'];
        $push['expense'][$i]->subCategoryId = $parent['subcategory'];
        $push['expense'][$i]->paymentMethodId = $parent['payment-method'];
        $push['product'][$i]->mainCategoryId = $parent['category'];
        $push['product'][$i]->subCategoryId = $parent['subcategory'];
        $push['product-price'][] = Records::make('product-price', [
            'productId' => $parent['product'],
            'source' => 'expense',
            'sourceExpenseId' => $parent['expense'],
        ]);
        $push['shopping-list-item'][] = Records::make('shopping-list-item', ['shoppingListId' => $parent['shopping-list']]);
        $push['budget-pocket'][] = Records::make('budget-pocket', ['categoryIds' => array_values(array_unique([
            RecordFields::id($push['category'][$i]),
            $parent['category'],
        ]))]);
    }

    return $push;
}

/**
 * The parents of the push's record #$i: of the same push for an even $i,
 * stored for it alone for an odd one.
 *
 * @param  array<string, list<stdClass>>  $push
 * @return array<string, string>
 */
function largePushParents(User $user, array $push, int $i): array
{
    if ($i % 2 === 0) {
        $resources = ['category', 'subcategory', 'payment-method', 'product', 'expense', 'shopping-list'];

        return array_map(static fn (string $resource): string => RecordFields::id($push[$resource][$i]), array_combine($resources, $resources));
    }

    $category = Records::stored('category', $user);

    return [
        'category' => RecordFields::id($category),
        'subcategory' => RecordFields::id(Records::stored('subcategory', $user, ['categoryId' => $category->id])),
        'payment-method' => RecordFields::id(Records::stored('payment-method', $user)),
        'product' => RecordFields::id(Records::stored('product', $user)),
        'expense' => RecordFields::id(Records::stored('expense', $user)),
        'shopping-list' => RecordFields::id(Records::stored('shopping-list', $user)),
    ];
}

/**
 * Runs the rules over the push, counting the queries they send per resource
 * and table (the server rows the sync loads itself are not counted).
 *
 * @param  array<string, list<stdClass>>  $push
 * @return array<string, array<string, int>>
 */
function countRuleQueries(User $user, array $push): array
{
    $context = new RuleContext($user, app(SyncStore::class));
    $counts = [];
    $current = null;

    DB::listen(static function (QueryExecuted $query) use (&$counts, &$current): void {
        /** @var string|null $current */
        /** @var array<string, array<string, int>> $counts */
        if ($current !== null && preg_match('/\bfrom\s+`?(\w+)`?/i', $query->sql, $match) === 1) {
            $counts[$current][$match[1]] = ($counts[$current][$match[1]] ?? 0) + 1;
        }
    });

    foreach (app(ResourceRegistry::class)->pushable() as $definition) {
        $records = $push[$definition->name];
        RuleRunner::loadExisting($context, $definition->name, $records);

        $current = $definition->name;
        $issues = app(DomainValidator::class)->validate($definition->name, $records, $context);
        $current = null;

        expect(array_filter($issues))->toBe([], $definition->name);

        foreach ($records as $record) {
            $context->accept($definition->name, $record);
        }
    }

    return $counts;
}

it('sends at most one query per resource and table, whatever the number of records', function (): void {
    $smallUser = User::factory()->createOne();
    $largeUser = User::factory()->createOne();
    $small = largePush($smallUser, 2);
    $large = largePush($largeUser, 56);

    foreach ($large as $resource => $records) {
        foreach ($records as $record) {
            TestCase::assertMatchesContract($resource, $record);
        }
    }

    $smallCounts = countRuleQueries($smallUser, $small);
    $largeCounts = countRuleQueries($largeUser, $large);

    expect(array_sum(array_map(count(...), $large)))->toBe(504)
        ->and($largeCounts)->toBe($smallCounts)
        ->and(max([0, ...array_merge(...array_values(array_map(array_values(...), $largeCounts)))]))->toBe(1);
});

/**
 * The package table of each pushable resource, and the pocket's pivot.
 *
 * @return array<string, string>
 */
function resourceTables(): array
{
    $tables = [PackageConfig::table(PIVOT) => 'budget-pocket'];

    foreach (app(ResourceRegistry::class)->pushable() as $definition) {
        $tables[(new $definition->model)->getTable()] = $definition->name;
    }

    return $tables;
}

/**
 * The contract field a column of a resource's table holds.
 */
function fieldOfColumn(string $resource, string $table, string $column): string
{
    foreach (app(ResourceRegistry::class)->mapper($resource)->describe() as $field) {
        $pivot = $field->type === FieldType::Links && $table === PackageConfig::table($field->column);

        if ($pivot || ($field->type !== FieldType::Links && $field->column === $column && $table !== PackageConfig::table(PIVOT))) {
            return $field->name;
        }
    }

    throw new LogicException("{$table}.{$column} holds no field of {$resource}.");
}

/**
 * @return list<array<array-key, mixed>>
 */
function informationSchema(string $sql): array
{
    return array_map(static function (mixed $row): array {
        assert(is_object($row));

        return get_object_vars($row);
    }, array_values(DB::select($sql, [DB::connection()->getDatabaseName()])));
}

it('checks exactly the references the foreign keys hold', function (): void {
    $tables = resourceTables();
    $foreignKeys = [];

    foreach (informationSchema(
        'SELECT TABLE_NAME AS child, REFERENCED_TABLE_NAME AS parent, GROUP_CONCAT(COLUMN_NAME) AS cols
         FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL
         GROUP BY TABLE_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME',
    ) as ['child' => $child, 'parent' => $parent, 'cols' => $cols]) {
        assert(is_string($child) && is_string($parent) && is_string($cols));
        $columns = array_values(array_diff(explode(',', $cols), ['user_id']));

        // The owner, a table outside the push (the users' default currency),
        // and the pivot row's own pocket are not references of a record.
        if ($columns === [] || ! isset($tables[$child]) || ($child === PackageConfig::table(PIVOT) && $columns === ['budget_pocket_id'])) {
            continue;
        }

        $target = $tables[$parent] ?? ($parent === (new Currency)->getTable() ? 'currency' : throw new LogicException("Unknown parent {$parent}."));
        $foreignKeys[] = sprintf('%s.%s -> %s', $tables[$child], fieldOfColumn($tables[$child], $child, $columns[0]), $target);
    }

    $rules = [];

    foreach (app(DomainValidator::class)->resources() as $resource) {
        foreach (app(DomainValidator::class)->batchRules($resource) as $rule) {
            if ($rule instanceof ReferencesExist) {
                foreach ($rule->references as $field => $target) {
                    $rules[] = "{$rule->resource}.{$field} -> {$target}";
                }
            }
        }
    }

    expect($foreignKeys)->not->toBeEmpty();
    expect($rules)->toEqualCanonicalizing($foreignKeys);
});

it('checks exactly the keys the unique indexes hold', function (): void {
    $tables = resourceTables();
    $generatedFrom = [];

    foreach (informationSchema(
        'SELECT TABLE_NAME AS t, COLUMN_NAME AS c, GENERATION_EXPRESSION AS e
         FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND GENERATION_EXPRESSION <> \'\'',
    ) as ['t' => $table, 'c' => $column, 'e' => $expression]) {
        assert(is_string($table) && is_string($column) && is_string($expression));
        preg_match_all('/`(\w+)`/', $expression, $matches);
        $generatedFrom[$table][$column] = array_values(array_diff($matches[1], ['deleted_at']))[0];
    }

    $indexes = [];

    foreach (informationSchema(
        'SELECT TABLE_NAME AS t, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = ? AND NON_UNIQUE = 0 AND INDEX_NAME <> \'PRIMARY\'
         GROUP BY TABLE_NAME, INDEX_NAME',
    ) as ['t' => $table, 'cols' => $cols]) {
        assert(is_string($table) && is_string($cols));
        $columns = array_values(array_diff(explode(',', $cols), ['user_id']));

        // The global sequence number is the sync's, not a key of the record.
        if (! isset($tables[$table]) || $columns === ['server_seq']) {
            continue;
        }

        $fields = array_map(
            static fn (string $column): string => fieldOfColumn($tables[$table], $table, $generatedFrom[$table][$column] ?? $column),
            $columns,
        );
        sort($fields);
        $indexes[] = $tables[$table].'('.implode(',', $fields).')';
    }

    $rules = [];

    foreach (app(DomainValidator::class)->resources() as $resource) {
        foreach (app(DomainValidator::class)->batchRules($resource) as $rule) {
            if ($rule instanceof UniqueAmongLiving) {
                foreach ($rule->keys as $field => $within) {
                    $fields = [...$within, $field];
                    sort($fields);
                    $rules[] = $rule->resource.'('.implode(',', $fields).')';
                }
            }
        }
    }

    expect($indexes)->not->toBeEmpty();
    expect($rules)->toEqualCanonicalizing($indexes);
});
