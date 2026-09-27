<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use TamasLabs\LaravelExpenses\Database\Currencies;
use TamasLabs\LaravelExpenses\Database\Factories\SyncModelFactory;
use TamasLabs\LaravelExpenses\Models\Currency;
use TamasLabs\LaravelExpenses\Rules\RecordFields;
use TamasLabs\LaravelExpenses\Support\Json;
use TamasLabs\LaravelExpenses\Support\PackageConfig;
use TamasLabs\LaravelExpenses\Tests\Fixtures\User;
use TamasLabs\LaravelExpenses\Tests\Support\Records;
use TamasLabs\LaravelExpenses\Tests\Support\RuleRunner;

// Spec 05, 4.2 — S1: a referenced record exists for the same user.

/**
 * @return array{path: string, keyword: string, message: string, params: array{resource: string, id: string}}
 */
function referenceIssue(string $path, string $resource, string $id): array
{
    return [
        'path' => $path,
        'keyword' => 'reference',
        'message' => "The referenced {$resource} does not exist.",
        'params' => ['resource' => $resource, 'id' => $id],
    ];
}

it('accepts references to the user\'s records', function (string $resource): void {
    $user = User::factory()->createOne();

    expect(RuleRunner::check($user, $resource, Records::withStoredParents($resource, $user)))->toBe([[]]);
})->with(['subcategory', 'expense', 'product', 'product-price', 'shopping-list-item', 'budget-pocket']);

it('rejects a reference to a record that does not exist', function (string $resource, string $field, string $target): void {
    $user = User::factory()->createOne();
    $record = Records::withStoredParents($resource, $user);
    $missing = SyncModelFactory::uuid();
    $record->{$field} = $missing;

    expect(RuleRunner::check($user, $resource, $record))->toBe([[referenceIssue("/{$field}", $target, $missing)]]);
})->with([
    'subcategory.categoryId' => ['subcategory', 'categoryId', 'category'],
    'expense.mainCategoryId' => ['expense', 'mainCategoryId', 'category'],
    'expense.subCategoryId' => ['expense', 'subCategoryId', 'subcategory'],
    'expense.paymentMethodId' => ['expense', 'paymentMethodId', 'payment-method'],
    'expense.currencyId' => ['expense', 'currencyId', 'currency'],
    'product.mainCategoryId' => ['product', 'mainCategoryId', 'category'],
    'product.subCategoryId' => ['product', 'subCategoryId', 'subcategory'],
    'product-price.productId' => ['product-price', 'productId', 'product'],
    'product-price.sourceExpenseId' => ['product-price', 'sourceExpenseId', 'expense'],
    'product-price.currencyId' => ['product-price', 'currencyId', 'currency'],
    'shopping-list-item.shoppingListId' => ['shopping-list-item', 'shoppingListId', 'shopping-list'],
]);

it('lets a record refer to a tombstone', function (): void {
    $user = User::factory()->createOne();
    $category = Records::stored('category', $user, ['deletedAt' => '2026-09-14T08:30:00.000Z']);

    expect(RuleRunner::check($user, 'expense', Records::make('expense', ['mainCategoryId' => $category->id])))->toBe([[]]);
});

it('checks the references of a tombstone too', function (): void {
    $user = User::factory()->createOne();
    $missing = SyncModelFactory::uuid();
    $expense = Records::make('expense', ['mainCategoryId' => $missing, 'deletedAt' => '2026-09-14T08:30:00.000Z']);

    expect(RuleRunner::check($user, 'expense', $expense))->toBe([[referenceIssue('/mainCategoryId', 'category', $missing)]]);
});

it('lets a record refer to a parent accepted earlier in the push', function (): void {
    $user = User::factory()->createOne();
    $category = Records::make('category');
    $subcategory = Records::make('subcategory', ['categoryId' => $category->id]);

    $results = RuleRunner::push($user, [
        'expense' => [Records::make('expense', ['mainCategoryId' => $category->id, 'subCategoryId' => $subcategory->id])],
        'subcategory' => [$subcategory],
        'category' => [$category],
    ]);

    expect($results)->toBe(['category' => [[]], 'subcategory' => [[]], 'expense' => [[]]]);
});

it('rejects a reference to a parent the push rejected, though an older version of it is stored', function (): void {
    $user = User::factory()->createOne();
    $food = Records::stored('category', $user, ['name' => 'Élelmiszer']);
    Records::stored('category', $user, ['name' => 'Háztartás']);

    $results = RuleRunner::push($user, [
        'category' => [Records::changed($food, ['name' => 'Háztartás'])],
        'expense' => [Records::make('expense', ['mainCategoryId' => $food->id])],
    ]);

    expect($results['category'][0][0]['keyword'] ?? null)->toBe('unique')
        ->and($results['expense'])->toBe([[referenceIssue('/mainCategoryId', 'category', RecordFields::id($food))]]);
});

it('answers a reference to another user\'s record exactly as one to a missing record', function (): void {
    $alice = User::factory()->createOne();
    $bob = User::factory()->createOne();
    $aliceCategory = Records::stored('category', $alice);
    $expense = Records::make('expense', ['mainCategoryId' => $aliceCategory->id]);

    $whileAliceHasIt = Json::encode(RuleRunner::check($bob, 'expense', $expense));

    DB::table(PackageConfig::table('categories'))->where('id', $aliceCategory->id)->delete();
    $whenNobodyHasIt = Json::encode(RuleRunner::check($bob, 'expense', $expense));

    expect($whileAliceHasIt)->toBe($whenNobodyHasIt)
        ->and($whenNobodyHasIt)->toBe(Json::encode([[referenceIssue('/mainCategoryId', 'category', RecordFields::id($aliceCategory))]]));
});

it('resolves a shared seed UUID to the user\'s own record', function (): void {
    $seed = '1b9d6bcd-bbfd-4b2d-9b5d-ab8dfbbd4bed';
    $alice = User::factory()->createOne();
    $bob = User::factory()->createOne();
    $carol = User::factory()->createOne();
    Records::stored('category', $alice, ['id' => $seed]);
    Records::stored('category', $bob, ['id' => $seed]);
    $expense = Records::make('expense', ['mainCategoryId' => $seed]);

    expect(RuleRunner::check($bob, 'expense', $expense))->toBe([[]])
        ->and(RuleRunner::check($carol, 'expense', $expense))->toBe([[referenceIssue('/mainCategoryId', 'category', $seed)]]);
});

it('does not check a null reference', function (): void {
    $user = User::factory()->createOne();

    expect(RuleRunner::check($user, 'expense', Records::make('expense')))->toBe([[]])
        ->and(RuleRunner::check($user, 'product', Records::make('product')))->toBe([[]])
        ->and(RuleRunner::check($user, 'product-price', Records::make('product-price', ['productId' => Records::stored('product', $user)->id])))->toBe([[]]);
});

it('checks a pocket\'s categories one by one', function (): void {
    $user = User::factory()->createOne();
    $missing = SyncModelFactory::uuid();
    $pocket = Records::make('budget-pocket', ['categoryIds' => [
        Records::stored('category', $user)->id,
        $missing,
        Records::stored('category', $user)->id,
    ]]);

    expect(RuleRunner::check($user, 'budget-pocket', $pocket))->toBe([[referenceIssue('/categoryIds/1', 'category', $missing)]]);
});

it('lets a retired currency through: it exists, and S3 decides', function (): void {
    $user = User::factory()->createOne();
    Currency::query()->whereKey(Currencies::USD)->update(['deleted_at' => '2026-09-01 00:00:00.000']);

    $issues = RuleRunner::check($user, 'expense', Records::make('expense', ['currencyId' => Currencies::USD]));

    expect(array_column($issues[0], 'keyword'))->toBe(['retired']);
});

it('looks a currency up in the global list, not among the user\'s rows', function (): void {
    $user = User::factory()->createOne();

    expect(RuleRunner::check($user, 'expense', Records::make('expense', ['currencyId' => Currencies::EUR])))->toBe([[]]);
});
