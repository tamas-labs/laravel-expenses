<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Tests\Support;

use stdClass;
use TamasLabs\LaravelExpenses\Database\Factories\SyncModelFactory;
use TamasLabs\LaravelExpenses\Tests\Fixtures\User;

/**
 * Contract records for the rule tests: the resource's example with a fresh
 * id, no unique value shared with another call, and the given fields.
 */
final class Records
{
    /**
     * Fields every record of the resource gets unless overridden: the
     * references a record can go without are null, the unique keys random.
     */
    private const array DEFAULTS = [
        'category' => [],
        'subcategory' => [],
        'payment-method' => [],
        'expense' => ['mainCategoryId' => null, 'subCategoryId' => null, 'paymentMethodId' => null],
        'product' => ['mainCategoryId' => null, 'subCategoryId' => null, 'barcode' => null],
        'product-price' => ['source' => 'manual', 'sourceExpenseId' => null],
        'shopping-list' => [],
        'shopping-list-item' => [],
        'budget-pocket' => ['categoryIds' => []],
    ];

    /**
     * @param  array<string, mixed>  $fields
     */
    public static function make(string $resource, array $fields = []): stdClass
    {
        $record = ContractSchema::example($resource);
        $record->id = SyncModelFactory::uuid();

        $unique = match ($resource) {
            'category', 'subcategory', 'payment-method' => ['name' => SyncModelFactory::uniqueName()],
            'product' => ['normalizedName' => SyncModelFactory::normalize(SyncModelFactory::uniqueName())],
            default => [],
        };

        foreach ([...self::DEFAULTS[$resource] ?? [], ...$unique, ...$fields] as $field => $value) {
            $record->{$field} = $value;
        }

        return $record;
    }

    /**
     * Makes the record and stores it as the user's, as if an earlier push had.
     *
     * @param  array<string, mixed>  $fields
     */
    public static function stored(string $resource, User $user, array $fields = []): stdClass
    {
        $record = self::make($resource, $fields);

        RecordStore::store($resource, $record, $user);

        return $record;
    }

    /**
     * A copy with the given fields changed and a later updatedAt: the next
     * version the client pushes.
     *
     * @param  array<string, mixed>  $fields
     */
    public static function changed(stdClass $record, array $fields): stdClass
    {
        $copy = clone $record;
        $copy->updatedAt = '2026-09-20T12:00:00.000Z';

        foreach ($fields as $field => $value) {
            $copy->{$field} = $value;
        }

        return $copy;
    }

    /**
     * The record as a tombstone.
     */
    public static function deleted(stdClass $record): stdClass
    {
        return self::changed($record, ['deletedAt' => '2026-09-20T12:00:00.000Z']);
    }

    /**
     * A record whose every reference points at a record stored for the user.
     */
    public static function withStoredParents(string $resource, User $user): stdClass
    {
        $category = fn (): stdClass => self::stored('category', $user);
        $subcategory = fn (): stdClass => self::stored('subcategory', $user, ['categoryId' => $category()->id]);

        return match ($resource) {
            'subcategory' => self::make('subcategory', ['categoryId' => $category()->id]),
            'expense' => self::make('expense', [
                'mainCategoryId' => $category()->id,
                'subCategoryId' => $subcategory()->id,
                'paymentMethodId' => self::stored('payment-method', $user)->id,
            ]),
            'product' => self::make('product', ['mainCategoryId' => $category()->id, 'subCategoryId' => $subcategory()->id]),
            'product-price' => self::make('product-price', [
                'productId' => self::stored('product', $user)->id,
                'source' => 'expense',
                'sourceExpenseId' => self::stored('expense', $user)->id,
            ]),
            'shopping-list-item' => self::make('shopping-list-item', ['shoppingListId' => self::stored('shopping-list', $user)->id]),
            'budget-pocket' => self::make('budget-pocket', ['categoryIds' => [$category()->id, $category()->id]]),
            default => self::make($resource),
        };
    }
}
