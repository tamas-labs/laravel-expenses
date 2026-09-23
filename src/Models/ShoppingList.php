<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Models;

use Carbon\CarbonImmutable;
use TamasLabs\LaravelExpenses\Database\Casts\UtcDateTime;

/**
 * @property string $name
 * @property string|null $shop
 * @property string $status
 * @property CarbonImmutable|null $archived_at
 */
final class ShoppingList extends SyncModel
{
    protected $table = 'shopping_lists';

    protected $hidden = ['user_id', 'server_seq', 'synced_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'archived_at' => UtcDateTime::class,
        ];
    }
}
