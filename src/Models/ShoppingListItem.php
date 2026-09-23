<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Models;

use TamasLabs\LaravelExpenses\Database\Casts\Double;

/**
 * @property string $shopping_list_id
 * @property string $name
 * @property string $normalized_name
 * @property float $quantity
 * @property string|null $unit
 * @property bool $is_checked
 * @property int $sort_order
 */
final class ShoppingListItem extends SyncModel
{
    protected $table = 'shopping_list_items';

    protected $hidden = ['user_id', 'server_seq', 'synced_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => Double::class,
            'is_checked' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
