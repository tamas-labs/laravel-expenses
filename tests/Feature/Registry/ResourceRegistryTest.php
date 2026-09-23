<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use TamasLabs\ExpensesSchema\ExpensesSchema;
use TamasLabs\LaravelExpenses\Mapping\RecordMapper;
use TamasLabs\LaravelExpenses\Registry\ResourceDefinition;
use TamasLabs\LaravelExpenses\Registry\ResourceRegistry;
use TamasLabs\LaravelExpenses\Registry\Scope;
use TamasLabs\LaravelExpenses\Support\PackageConfig;
use TamasLabs\LaravelExpenses\Tests\Fixtures\User;

// Spec 04, 3.1: the one list of the resources.

it('registers exactly the contract\'s resources, in its order', function (): void {
    expect(app(ResourceRegistry::class)->names())->toBe(ExpensesSchema::resources());
});

it('takes the minor each resource appeared in from the contract', function (string $resource): void {
    expect(app(ResourceRegistry::class)->get($resource)->since)->toBe(ExpensesSchema::resourceSince($resource));
})->with(ExpensesSchema::resources());

it('describes every resource as the spec lists it', function (): void {
    $rows = array_map(
        static fn (ResourceDefinition $definition): string => sprintf(
            '%s %s %s %s',
            $definition->name,
            $definition->scope->value,
            $definition->clientWritable ? 'writable' : 'read-only',
            $definition->applyOrder ?? '-',
        ),
        app(ResourceRegistry::class)->all(),
    );

    expect($rows)->toBe([
        'user account read-only -',
        'currency global read-only -',
        'category owned writable 1',
        'subcategory owned writable 2',
        'payment-method owned writable 3',
        'expense owned writable 4',
        'product owned writable 5',
        'product-price owned writable 6',
        'shopping-list owned writable 7',
        'shopping-list-item owned writable 8',
        'budget-pocket owned writable 9',
    ]);
});

it('lists the pushable resources in their apply order', function (): void {
    $names = array_map(static fn (ResourceDefinition $definition): string => $definition->name, app(ResourceRegistry::class)->pushable());

    expect($names)->toBe([
        'category',
        'subcategory',
        'payment-method',
        'expense',
        'product',
        'product-price',
        'shopping-list',
        'shopping-list-item',
        'budget-pocket',
    ]);
});

it('uses the host\'s user model for the user record', function (): void {
    expect(app(ResourceRegistry::class)->get('user')->model)->toBe(User::class)
        ->and(app(ResourceRegistry::class)->get('user')->scope)->toBe(Scope::Account);
});

it('gives every resource a mapper', function (string $resource): void {
    expect(app(ResourceRegistry::class)->mapper($resource))->toBeInstanceOf(RecordMapper::class)
        ->toBeInstanceOf(app(ResourceRegistry::class)->get($resource)->mapper);
})->with(ExpensesSchema::resources());

it('is a singleton', function (): void {
    expect(app(ResourceRegistry::class))->toBe(app(ResourceRegistry::class));
});

it('does not know other resources', function (): void {
    expect(app(ResourceRegistry::class)->has('invoice'))->toBeFalse();

    app(ResourceRegistry::class)->get('invoice');
})->throws(InvalidArgumentException::class, 'invoice');

it('pushes every parent before its children', function (): void {
    $registry = app(ResourceRegistry::class);
    $resourceOf = [PackageConfig::table('budget_pocket_categories') => 'budget-pocket'];

    foreach ($registry->all() as $definition) {
        $resourceOf[(new $definition->model)->getTable()] = $definition->name;
    }

    $foreignKeys = DB::select(
        'SELECT DISTINCT TABLE_NAME AS child, REFERENCED_TABLE_NAME AS parent
         FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
        [DB::connection()->getDatabaseName()],
    );

    expect($foreignKeys)->not->toBeEmpty();

    foreach ($foreignKeys as $foreignKey) {
        ['child' => $childTable, 'parent' => $parentTable] = (array) $foreignKey;
        assert(is_string($childTable) && is_string($parentTable));
        expect($resourceOf)->toHaveKeys([$childTable, $parentTable]);

        $child = $registry->get($resourceOf[$childTable]);
        $parent = $registry->get($resourceOf[$parentTable]);

        // A parent the client cannot push (the user, a currency) is there before any push.
        if ($child->name === $parent->name || $child->applyOrder === null || $parent->applyOrder === null) {
            continue;
        }

        expect($parent->applyOrder)->toBeLessThan($child->applyOrder, "{$childTable} -> {$parentTable}");
    }
});
