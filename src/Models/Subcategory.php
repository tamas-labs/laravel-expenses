<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Models;

/**
 * @property string $category_id
 * @property string $name
 * @property int|null $sort_order
 */
final class Subcategory extends SyncModel
{
    protected $table = 'subcategories';

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
