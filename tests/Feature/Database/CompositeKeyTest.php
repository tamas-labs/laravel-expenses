<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use TamasLabs\LaravelExpenses\Database\Factories\CategoryFactory;
use TamasLabs\LaravelExpenses\Database\Factories\ExpenseFactory;
use TamasLabs\LaravelExpenses\Models\Category;
use TamasLabs\LaravelExpenses\Tests\Fixtures\User;

// Spec 03, 3.3 and 3.13: a row is (user_id, id); the client's seed records
// share their UUID across users.

const SEED_CATEGORY = '00000000-0000-4000-8100-000000000001';

/**
 * @return array{User, User, Category, Category}
 */
function twoUsersWithTheSeedCategory(): array
{
    $alice = User::factory()->createOne();
    $bob = User::factory()->createOne();

    return [
        $alice,
        $bob,
        CategoryFactory::new()->seed()->ownedBy($alice)->createOne(['name' => 'Élelmiszer']),
        CategoryFactory::new()->seed()->ownedBy($bob)->createOne(['name' => 'Élelmiszer']),
    ];
}

function categoryNameOf(User $user): string
{
    return Category::query()->ownedBy($user)->whereKey(SEED_CATEGORY)->sole()->name;
}

it('stores the same seed UUID once per user', function (): void {
    [$alice, $bob] = twoUsersWithTheSeedCategory();

    expect(Category::query()->whereKey(SEED_CATEGORY)->count())->toBe(2)
        ->and(Category::query()->ownedBy($alice)->whereKey(SEED_CATEGORY)->count())->toBe(1)
        ->and(Category::query()->ownedBy($bob)->whereKey(SEED_CATEGORY)->count())->toBe(1);
});

it('saves only the row of its own user', function (): void {
    [$alice, $bob, $alicesCategory] = twoUsersWithTheSeedCategory();

    $alicesCategory->name = 'Bevásárlás';
    $alicesCategory->save();

    expect(categoryNameOf($alice))->toBe('Bevásárlás')
        ->and(categoryNameOf($bob))->toBe('Élelmiszer');
});

it('updates only the row of its own user', function (): void {
    [$alice, $bob, $alicesCategory] = twoUsersWithTheSeedCategory();
    Category::query()->update(['sort_order' => 5]);

    Model::unguarded(fn (): bool => $alicesCategory->update(['name' => 'Bevásárlás']));
    $alicesCategory->increment('sort_order');

    expect(categoryNameOf($alice))->toBe('Bevásárlás')
        ->and(categoryNameOf($bob))->toBe('Élelmiszer')
        ->and(Category::query()->ownedBy($alice)->sole()->sort_order)->toBe(6)
        ->and(Category::query()->ownedBy($bob)->sole()->sort_order)->toBe(5);
});

it('deletes only the row of its own user', function (): void {
    [$alice, $bob, $alicesCategory] = twoUsersWithTheSeedCategory();

    $alicesCategory->delete();

    expect(Category::query()->ownedBy($alice)->count())->toBe(0)
        ->and(Category::query()->ownedBy($bob)->count())->toBe(1);
});

it('refreshes from the row of its own user', function (): void {
    [, $bob, $alicesCategory, $bobsCategory] = twoUsersWithTheSeedCategory();

    Category::query()->ownedBy($bob)->update(['name' => 'Bob élelmiszere']);

    expect($alicesCategory->fresh()?->name)->toBe('Élelmiszer')
        ->and($alicesCategory->refresh()->name)->toBe('Élelmiszer')
        ->and($bobsCategory->fresh()?->name)->toBe('Bob élelmiszere');
});

it('resolves a reference to a seed UUID to the row of the same user', function (): void {
    $alice = User::factory()->createOne();
    $bob = User::factory()->createOne();
    CategoryFactory::new()->seed()->ownedBy($bob)->createOne();

    // Bob has the seed category, Alice does not: her expense cannot point at it.
    expect(fn (): mixed => ExpenseFactory::new()->ownedBy($alice)->createOne(['main_category_id' => SEED_CATEGORY]))
        ->toThrow(QueryException::class, 'foreign key constraint fails');

    CategoryFactory::new()->seed()->ownedBy($alice)->createOne();
    $expense = ExpenseFactory::new()->ownedBy($alice)->createOne(['main_category_id' => SEED_CATEGORY]);

    expect($expense->main_category_id)->toBe(SEED_CATEGORY);
});

it('refuses route model binding', function (): void {
    (new Category)->resolveRouteBinding(SEED_CATEGORY);
})->throws(LogicException::class, 'only unique per user');
