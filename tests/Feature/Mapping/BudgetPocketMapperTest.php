<?php

declare(strict_types=1);

use TamasLabs\LaravelExpenses\Database\Factories\BudgetPocketFactory;
use TamasLabs\LaravelExpenses\Models\BudgetPocket;
use TamasLabs\LaravelExpenses\Models\Category;
use TamasLabs\LaravelExpenses\Registry\ResourceRegistry;
use TamasLabs\LaravelExpenses\Tests\Fixtures\UserFactory;
use TamasLabs\LaravelExpenses\Tests\Support\ContractSchema;
use TamasLabs\LaravelExpenses\Tests\Support\RecordStore;

// Spec 04, 3.6: a pocket's categoryIds come from and go to the pivot.

it('hands the categoryIds to the storage layer as links, not as an attribute', function (): void {
    $pocket = ContractSchema::example('budget-pocket');
    $pocket->categoryIds = ['00000000-0000-4000-8100-000000000002', '00000000-0000-4000-8100-000000000001'];

    $mapped = app(ResourceRegistry::class)->mapper('budget-pocket')->toAttributes($pocket);

    expect($mapped->links)->toBe(['categoryIds' => ['00000000-0000-4000-8100-000000000002', '00000000-0000-4000-8100-000000000001']])
        ->and($mapped->attributes)->not->toHaveKey('categoryIds');
});

it('sends the categoryIds sorted by UUID', function (): void {
    $pocket = BudgetPocketFactory::new()->makeOne();
    $pocket->categoryIds = [
        'c0000000-0000-4000-8000-000000000000',
        '0a000000-0000-4000-8000-000000000000',
        'b0000000-0000-4000-8000-000000000000',
    ];

    expect(app(ResourceRegistry::class)->mapper('budget-pocket')->toPayload($pocket)['categoryIds'])->toBe([
        '0a000000-0000-4000-8000-000000000000',
        'b0000000-0000-4000-8000-000000000000',
        'c0000000-0000-4000-8000-000000000000',
    ]);
});

it('sends the categories stored in the pivot', function (): void {
    $user = UserFactory::new()->createOne();
    $pocket = BudgetPocketFactory::new()->ownedBy($user)->withCategories(3)->createOne();
    $categoryIds = Category::query()
        ->ownedBy($user)
        ->pluck('id')
        ->sort()
        ->values()
        ->all();

    $payload = app(ResourceRegistry::class)->mapper('budget-pocket')->toPayload(RecordStore::loadCategoryIds($pocket));

    expect($payload['categoryIds'])->toBe($categoryIds)
        ->and($categoryIds)->toHaveCount(3);
});

it('sends a pocket without categories as an empty list', function (): void {
    $pocket = BudgetPocketFactory::new()->createOne();

    expect(app(ResourceRegistry::class)->mapper('budget-pocket')->toPayload(RecordStore::loadCategoryIds($pocket))['categoryIds'])->toBe([]);
});

it('refuses to send categoryIds that were never loaded', function (): void {
    $pocket = BudgetPocketFactory::new()->withCategories()->createOne()->fresh();

    expect($pocket?->categoryIds)->toBeNull();

    app(ResourceRegistry::class)->mapper('budget-pocket')->toPayload($pocket ?? new BudgetPocket);
})->throws(LogicException::class, 'not loaded');
