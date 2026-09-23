<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Database\Factories;

use TamasLabs\LaravelExpenses\Models\ShoppingList;

/**
 * An active shopping list.
 *
 * @extends SyncModelFactory<ShoppingList>
 */
final class ShoppingListFactory extends SyncModelFactory
{
    protected $model = ShoppingList::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            ...$this->syncAttributes(),
            'name' => self::uniqueName(),
            'shop' => null,
            'status' => 'active',
            'archived_at' => null,
        ];
    }
}
