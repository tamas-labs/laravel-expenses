<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Models;

/**
 * @property string $name
 * @property int|null $sort_order
 * @property string|null $color
 * @property string|null $icon
 */
final class Category extends SyncModel
{
    protected $table = 'categories';

    protected $hidden = ['user_id', 'server_seq', 'synced_at', 'name_key'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }
}
