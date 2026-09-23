<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Database\Factories;

use TamasLabs\LaravelExpenses\Models\ShoppingListItem;

/**
 * @extends SyncModelFactory<ShoppingListItem>
 */
final class ShoppingListItemFactory extends SyncModelFactory
{
    protected $model = ShoppingListItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = self::uniqueName();

        return [
            ...$this->syncAttributes(),
            'shopping_list_id' => self::parent(ShoppingListFactory::new()),
            'name' => $name,
            'normalized_name' => self::normalize($name),
            'quantity' => fake()->randomFloat(2, 0.25, 10),
            'unit' => null,
            'is_checked' => false,
            'sort_order' => fake()->numberBetween(0, 100),
        ];
    }
}
