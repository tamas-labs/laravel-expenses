<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use TamasLabs\LaravelExpenses\Database\Factories\BudgetPocketFactory;
use TamasLabs\LaravelExpenses\Database\Factories\CategoryFactory;
use TamasLabs\LaravelExpenses\Database\Factories\ExpenseFactory;
use TamasLabs\LaravelExpenses\Database\Factories\ProductFactory;
use TamasLabs\LaravelExpenses\Database\Factories\ProductPriceFactory;
use TamasLabs\LaravelExpenses\Database\Factories\SubcategoryFactory;
use TamasLabs\LaravelExpenses\Models\Category;
use TamasLabs\LaravelExpenses\Support\PackageConfig;
use TamasLabs\LaravelExpenses\Tests\Fixtures\User;

// Spec 03, 3.3 (D4): the database itself keeps every reference within one
// user, whatever the application code does.

const FK_VIOLATION = 'a foreign key constraint fails';

it('rejects a reference to a row of another user', function (Closure $reference): void {
    $alice = User::factory()->createOne();
    $bob = User::factory()->createOne();

    expect(fn (): mixed => $reference($alice, $bob))->toThrow(QueryException::class, FK_VIOLATION);
})->with([
    'expense → category' => fn (User $alice, User $bob): mixed => ExpenseFactory::new()->ownedBy($alice)
        ->createOne(['main_category_id' => CategoryFactory::new()->ownedBy($bob)->createOne()->id]),
    'subcategory → category' => fn (User $alice, User $bob): mixed => SubcategoryFactory::new()->ownedBy($alice)
        ->createOne(['category_id' => CategoryFactory::new()->ownedBy($bob)->createOne()->id]),
    'product price → product' => fn (User $alice, User $bob): mixed => ProductPriceFactory::new()->ownedBy($alice)
        ->createOne(['product_id' => ProductFactory::new()->ownedBy($bob)->createOne()->id]),
    'product price → expense' => fn (User $alice, User $bob): mixed => ProductPriceFactory::new()->ownedBy($alice)
        ->createOne(['source' => 'expense', 'source_expense_id' => ExpenseFactory::new()->ownedBy($bob)->createOne()->id]),
    'pocket → category (pivot)' => fn (User $alice, User $bob): bool => DB::table(PackageConfig::table('budget_pocket_categories'))->insert([
        'user_id' => $alice->id,
        'budget_pocket_id' => BudgetPocketFactory::new()->ownedBy($alice)->createOne()->id,
        'category_id' => CategoryFactory::new()->ownedBy($bob)->createOne()->id,
    ]),
]);

it('accepts a reference to a row of the same user', function (): void {
    $alice = User::factory()->createOne();
    $category = CategoryFactory::new()->ownedBy($alice)->createOne();

    $expense = ExpenseFactory::new()->ownedBy($alice)->createOne(['main_category_id' => $category->id]);

    expect($expense->main_category_id)->toBe($category->id);
});

it('accepts a reference to a tombstone', function (): void {
    $alice = User::factory()->createOne();
    $category = CategoryFactory::new()->ownedBy($alice)->deleted()->createOne();

    $expense = ExpenseFactory::new()->ownedBy($alice)->createOne(['main_category_id' => $category->id]);

    expect($expense->main_category_id)->toBe($category->id);
});

it('refuses to physically delete a referenced row', function (): void {
    $alice = User::factory()->createOne();
    $category = CategoryFactory::new()->ownedBy($alice)->deleted()->createOne();
    ExpenseFactory::new()->ownedBy($alice)->createOne(['main_category_id' => $category->id]);

    expect(fn (): mixed => $category->delete())
        ->toThrow(QueryException::class, 'Cannot delete or update a parent row');

    expect(Category::query()->ownedBy($alice)->count())->toBe(1);
});

it('refuses to delete a user who still owns rows', function (): void {
    $alice = User::factory()->createOne();
    CategoryFactory::new()->ownedBy($alice)->createOne();

    expect(fn (): mixed => $alice->delete())
        ->toThrow(QueryException::class, 'Cannot delete or update a parent row');
});
