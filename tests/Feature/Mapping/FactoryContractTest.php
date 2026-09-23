<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use TamasLabs\LaravelExpenses\Database\Factories\BudgetPocketFactory;
use TamasLabs\LaravelExpenses\Database\Factories\CategoryFactory;
use TamasLabs\LaravelExpenses\Database\Factories\CurrencyFactory;
use TamasLabs\LaravelExpenses\Database\Factories\ExpenseFactory;
use TamasLabs\LaravelExpenses\Database\Factories\PaymentMethodFactory;
use TamasLabs\LaravelExpenses\Database\Factories\ProductFactory;
use TamasLabs\LaravelExpenses\Database\Factories\ProductPriceFactory;
use TamasLabs\LaravelExpenses\Database\Factories\ShoppingListFactory;
use TamasLabs\LaravelExpenses\Database\Factories\ShoppingListItemFactory;
use TamasLabs\LaravelExpenses\Database\Factories\SubcategoryFactory;
use TamasLabs\LaravelExpenses\Models\BudgetPocket;
use TamasLabs\LaravelExpenses\Tests\Support\RecordStore;
use TamasLabs\LaravelExpenses\Tests\TestCase;

// Spec 04, 4.4 (moved here from 03, 3.14): what the factories store is a
// valid contract record once it goes through the mapper.

/**
 * @return array<string, array{string, Closure(): Factory<covariant Model>}>
 */
function factoryContractCases(): array
{
    $factories = [
        'currency' => static fn (): CurrencyFactory => CurrencyFactory::new(),
        'category' => static fn (): CategoryFactory => CategoryFactory::new(),
        'subcategory' => static fn (): SubcategoryFactory => SubcategoryFactory::new(),
        'payment-method' => static fn (): PaymentMethodFactory => PaymentMethodFactory::new(),
        'expense' => static fn (): ExpenseFactory => ExpenseFactory::new(),
        'product' => static fn (): ProductFactory => ProductFactory::new(),
        'product-price' => static fn (): ProductPriceFactory => ProductPriceFactory::new(),
        'shopping-list' => static fn (): ShoppingListFactory => ShoppingListFactory::new(),
        'shopping-list-item' => static fn (): ShoppingListItemFactory => ShoppingListItemFactory::new(),
        'budget-pocket' => static fn (): BudgetPocketFactory => BudgetPocketFactory::new(),
    ];
    $cases = [];

    foreach ($factories as $resource => $factory) {
        $cases[$resource] = [$resource, $factory];
        $cases["{$resource} deleted"] = [$resource, static fn (): Factory => $factory()->deleted()];
    }

    $cases['category seed'] = ['category', static fn (): Factory => CategoryFactory::new()->seed()];
    $cases['subcategory seed'] = ['subcategory', static fn (): Factory => SubcategoryFactory::new()->seed()];
    $cases['payment-method seed'] = ['payment-method', static fn (): Factory => PaymentMethodFactory::new()->seed()];
    $cases['budget-pocket with categories'] = ['budget-pocket', static fn (): Factory => BudgetPocketFactory::new()->withCategories(2)];

    return $cases;
}

it('builds rows the mapper turns into valid records', function (string $resource, Closure $factory): void {
    $built = $factory();
    assert($built instanceof Factory);

    $model = $built->createOne()->fresh();
    assert($model !== null);

    if ($model instanceof BudgetPocket) {
        RecordStore::loadCategoryIds($model);
    }

    TestCase::assertMatchesContract($resource, RecordStore::registry()->mapper($resource)->toPayload($model));
})->with(factoryContractCases());
