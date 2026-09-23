<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
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
use TamasLabs\LaravelExpenses\Database\Factories\SyncModelFactory;
use TamasLabs\LaravelExpenses\Models\Category;
use TamasLabs\LaravelExpenses\Models\Subcategory;
use TamasLabs\LaravelExpenses\Models\SyncModel;
use TamasLabs\LaravelExpenses\Support\PackageConfig;
use TamasLabs\LaravelExpenses\Tests\Support\SchemaInspector;

// Spec 03, 3.14. That the rows also satisfy the contract is proven through
// the mappers in spec 04.

/**
 * @return array<string, SyncModelFactory<covariant SyncModel>|CurrencyFactory>
 */
function everyFactory(): array
{
    return [
        'currency' => CurrencyFactory::new(),
        'category' => CategoryFactory::new(),
        'subcategory' => SubcategoryFactory::new(),
        'payment method' => PaymentMethodFactory::new(),
        'expense' => ExpenseFactory::new(),
        'product' => ProductFactory::new(),
        'product price' => ProductPriceFactory::new(),
        'shopping list' => ShoppingListFactory::new(),
        'shopping list item' => ShoppingListItemFactory::new(),
        'budget pocket' => BudgetPocketFactory::new(),
    ];
}

it('saves a row', function (string $name): void {
    $factory = everyFactory()[$name];

    $model = $factory->createOne();

    expect($model->exists)->toBeTrue()
        ->and($model->fresh())->not->toBeNull();
})->with(array_keys(everyFactory()));

it('saves a tombstone', function (string $name): void {
    $model = everyFactory()[$name]->deleted()->createOne()->fresh();

    expect($model?->getAttribute('deleted_at'))->not->toBeNull();
})->with(array_keys(everyFactory()));

it('creates the parents for the same user', function (): void {
    $subcategory = SubcategoryFactory::new()->createOne();

    expect(Category::query()->whereKey($subcategory->category_id)->sole()->user_id)->toBe($subcategory->user_id);
});

it('gives the seed state the fixed client UUIDs', function (): void {
    expect(CategoryFactory::new()->seed(3)->makeOne()->id)->toBe('00000000-0000-4000-8100-000000000003')
        ->and(SubcategoryFactory::new()->seed(12)->makeOne(['category_id' => 'x'])->id)->toBe('00000000-0000-4000-8200-000000000012')
        ->and(PaymentMethodFactory::new()->seed()->makeOne()->id)->toBe('00000000-0000-4000-8300-000000000001');
});

it('links a pocket to categories of the same user', function (): void {
    $pocket = BudgetPocketFactory::new()->withCategories(2)->createOne();

    $links = DB::table(PackageConfig::table('budget_pocket_categories'))->where('budget_pocket_id', $pocket->id)->get();

    expect($links)->toHaveCount(2)
        ->and($links->pluck('user_id')->unique()->all())->toBe([$pocket->user_id]);
});

it('hides the internal columns of every model', function (string $name): void {
    $model = everyFactory()[$name]->createOne()->fresh();
    assert($model !== null);

    $generated = array_keys(array_filter(
        SchemaInspector::current()->columns($model->getTable()),
        static fn (string $column): bool => str_ends_with($column, ' generated'),
    ));

    expect(array_keys($model->toArray()))->not->toContain('server_seq', 'synced_at', ...$generated);

    if ($model->getAttribute('user_id') !== null) {
        expect(array_keys($model->toArray()))->not->toContain('user_id');
    }
})->with(array_keys(everyFactory()));

it('builds subcategories for the category it is given', function (): void {
    $category = CategoryFactory::new()->createOne();

    $subcategory = SubcategoryFactory::new()->ownedBy($category->user_id)->createOne(['category_id' => $category->id]);

    expect(Subcategory::query()->count())->toBe(1)
        ->and($subcategory->category_id)->toBe($category->id);
});
