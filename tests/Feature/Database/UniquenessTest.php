<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use TamasLabs\LaravelExpenses\Database\Factories\CategoryFactory;
use TamasLabs\LaravelExpenses\Database\Factories\ProductFactory;
use TamasLabs\LaravelExpenses\Database\Factories\SubcategoryFactory;
use TamasLabs\LaravelExpenses\Models\Category;
use TamasLabs\LaravelExpenses\Models\Product;
use TamasLabs\LaravelExpenses\Tests\Fixtures\User;

// Spec 03, 3.6 (D3): unique among the live rows of one user, byte-exact.

const DUPLICATE = 'Duplicate entry';

it('rejects two live categories with the same name', function (): void {
    $alice = User::factory()->createOne();
    CategoryFactory::new()->ownedBy($alice)->createOne(['name' => 'Élelmiszer']);

    expect(fn (): mixed => CategoryFactory::new()->ownedBy($alice)->createOne(['name' => 'Élelmiszer']))
        ->toThrow(QueryException::class, DUPLICATE);
});

it('lets a live category reuse the name of a deleted one', function (): void {
    $alice = User::factory()->createOne();
    CategoryFactory::new()->ownedBy($alice)->deleted()->createOne(['name' => 'Élelmiszer']);
    CategoryFactory::new()->ownedBy($alice)->deleted()->createOne(['name' => 'Élelmiszer']);
    CategoryFactory::new()->ownedBy($alice)->createOne(['name' => 'Élelmiszer']);

    expect(Category::query()->ownedBy($alice)->where('name', 'Élelmiszer')->count())->toBe(3)
        ->and(Category::query()->ownedBy($alice)->alive()->where('name', 'Élelmiszer')->count())->toBe(1);
});

it('frees the name when a category becomes a tombstone', function (): void {
    $alice = User::factory()->createOne();
    $old = CategoryFactory::new()->ownedBy($alice)->createOne(['name' => 'Élelmiszer']);

    $old->deleted_at = $old->updated_at;
    $old->save();
    CategoryFactory::new()->ownedBy($alice)->createOne(['name' => 'Élelmiszer']);

    expect(Category::query()->ownedBy($alice)->alive()->sole()->id)->not->toBe($old->id);
});

it('lets two users have a category of the same name', function (): void {
    CategoryFactory::new()->createOne(['name' => 'Élelmiszer']);
    CategoryFactory::new()->createOne(['name' => 'Élelmiszer']);

    expect(Category::query()->where('name', 'Élelmiszer')->count())->toBe(2);
});

it('compares names byte for byte', function (string $other): void {
    $alice = User::factory()->createOne();
    CategoryFactory::new()->ownedBy($alice)->createOne(['name' => 'Élelmiszer']);
    CategoryFactory::new()->ownedBy($alice)->createOne(['name' => $other]);

    expect(Category::query()->ownedBy($alice)->count())->toBe(2);
})->with([
    'case' => 'élelmiszer',
    'accent' => 'Elelmiszer',
    'trailing space' => 'Élelmiszer ',
    'decomposed accent (NFD)' => "E\u{0301}lelmiszer",
]);

it('keeps subcategory names unique within their category only', function (): void {
    $alice = User::factory()->createOne();
    $food = CategoryFactory::new()->ownedBy($alice)->createOne();
    $home = CategoryFactory::new()->ownedBy($alice)->createOne();

    SubcategoryFactory::new()->ownedBy($alice)->createOne(['category_id' => $food->id, 'name' => 'Egyéb']);
    SubcategoryFactory::new()->ownedBy($alice)->createOne(['category_id' => $home->id, 'name' => 'Egyéb']);

    expect(fn (): mixed => SubcategoryFactory::new()->ownedBy($alice)->createOne(['category_id' => $food->id, 'name' => 'Egyéb']))
        ->toThrow(QueryException::class, DUPLICATE);
});

it('keeps product normalized names unique among live products', function (): void {
    $alice = User::factory()->createOne();
    ProductFactory::new()->ownedBy($alice)->deleted()->createOne(['normalized_name' => 'tej 2 8']);
    ProductFactory::new()->ownedBy($alice)->createOne(['normalized_name' => 'tej 2 8']);
    ProductFactory::new()->createOne(['normalized_name' => 'tej 2 8']);

    expect(fn (): mixed => ProductFactory::new()->ownedBy($alice)->createOne(['normalized_name' => 'tej 2 8']))
        ->toThrow(QueryException::class, DUPLICATE);
});

it('keeps product barcodes unique among live products', function (): void {
    $alice = User::factory()->createOne();
    ProductFactory::new()->ownedBy($alice)->deleted()->createOne(['barcode' => '5998200123456']);
    ProductFactory::new()->ownedBy($alice)->createOne(['barcode' => '5998200123456']);
    ProductFactory::new()->createOne(['barcode' => '5998200123456']);

    expect(fn (): mixed => ProductFactory::new()->ownedBy($alice)->createOne(['barcode' => '5998200123456']))
        ->toThrow(QueryException::class, DUPLICATE);
});

it('lets any number of products go without a barcode', function (): void {
    $alice = User::factory()->createOne();
    ProductFactory::new()->ownedBy($alice)->count(3)->create(['barcode' => null]);

    expect(Product::query()->ownedBy($alice)->whereNull('barcode')->count())->toBe(3);
});
