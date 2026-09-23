<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use TamasLabs\LaravelExpenses\Database\Factories\CategoryFactory;
use TamasLabs\LaravelExpenses\Database\Factories\ProductFactory;
use TamasLabs\LaravelExpenses\Database\Factories\ShoppingListItemFactory;
use TamasLabs\LaravelExpenses\Models\Category;
use TamasLabs\LaravelExpenses\Models\ShoppingListItem;
use TamasLabs\LaravelExpenses\Support\PackageConfig;
use TamasLabs\LaravelExpenses\Tests\Fixtures\User;

// Spec 03: what the client sends comes back bit for bit (3.4, 3.7, 3.8).

function storedCategoryColumn(Category $category, string $column): mixed
{
    return DB::table(PackageConfig::table('categories'))
        ->where('user_id', $category->user_id)
        ->where('id', $category->id)
        ->value($column);
}

it('keeps the milliseconds of a timestamp', function (): void {
    $category = CategoryFactory::new()->createOne([
        'created_at' => CarbonImmutable::parse('2026-09-14 08:30:00.123', 'UTC'),
    ]);

    expect(storedCategoryColumn($category, 'created_at'))->toBe('2026-09-14 08:30:00.123')
        ->and($category->fresh()?->created_at->format('Y-m-d H:i:s.v e'))->toBe('2026-09-14 08:30:00.123 UTC');
});

it('stores timestamps after 2038', function (): void {
    $category = CategoryFactory::new()->createOne([
        'updated_at' => CarbonImmutable::parse('2045-01-01 00:00:00.000', 'UTC'),
    ]);

    expect(storedCategoryColumn($category, 'updated_at'))->toBe('2045-01-01 00:00:00.000');
});

it('truncates a timestamp to the millisecond instead of rounding it', function (): void {
    $category = CategoryFactory::new()->createOne([
        'created_at' => CarbonImmutable::parse('2026-09-14 08:30:59.999999', 'UTC'),
    ]);

    expect(storedCategoryColumn($category, 'created_at'))->toBe('2026-09-14 08:30:59.999');
});

describe('in an app running in Europe/Budapest', function (): void {
    beforeEach(function (): void {
        config()->set('app.timezone', 'Europe/Budapest');
        date_default_timezone_set('Europe/Budapest');
    });

    afterEach(function (): void {
        date_default_timezone_set('UTC');
    });

    it('reads a stored timestamp as UTC', function (): void {
        $category = CategoryFactory::new()->createOne([
            'created_at' => CarbonImmutable::parse('2026-09-14 08:30:00.123', 'UTC'),
        ]);

        $createdAt = $category->fresh()?->created_at;

        expect($createdAt?->getTimezone()->getName())->toBe('UTC')
            ->and($createdAt?->format('Y-m-d H:i:s.v'))->toBe('2026-09-14 08:30:00.123');
    });

    it('stores a local time converted to UTC', function (): void {
        $category = CategoryFactory::new()->createOne([
            'created_at' => CarbonImmutable::parse('2026-09-14 10:30:00.123'),
        ]);

        expect(storedCategoryColumn($category, 'created_at'))->toBe('2026-09-14 08:30:00.123');
    });
});

it('stores a double bit for bit', function (float $quantity): void {
    $item = ShoppingListItemFactory::new()->createOne(['quantity' => $quantity]);

    $stored = DB::table(PackageConfig::table('shopping_list_items'))
        ->where('user_id', $item->user_id)
        ->where('id', $item->id)
        ->value('quantity');

    expect($stored)->toBe($quantity)
        ->and(ShoppingListItem::query()->ownedBy(User::query()->findOrFail($item->user_id))->sole()->quantity)->toBe($quantity);
})->with([
    '0.1' => 0.1,
    '1/3' => 1 / 3,
    '2.8' => 2.8,
    '1e25' => 1e25,
    '5e-324' => 5e-324,
]);

it('keeps the key order of a JSON text column', function (): void {
    $product = ProductFactory::new()->createOne(['custom_fields' => '{"b":1,"a":2}']);

    $stored = DB::table(PackageConfig::table('products'))
        ->where('user_id', $product->user_id)
        ->where('id', $product->id)
        ->value('custom_fields');

    expect($stored)->toBe('{"b":1,"a":2}');
});
