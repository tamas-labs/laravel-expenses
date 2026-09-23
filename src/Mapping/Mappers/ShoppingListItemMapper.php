<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Mapping\Mappers;

use TamasLabs\LaravelExpenses\Mapping\Field;
use TamasLabs\LaravelExpenses\Mapping\RecordMapper;

final class ShoppingListItemMapper extends RecordMapper
{
    /**
     * @return list<Field>
     */
    protected function fields(): array
    {
        return [
            Field::uuid('id', 'id'),
            Field::uuid('shoppingListId', 'shopping_list_id'),
            Field::text('name', 'name'),
            Field::text('normalizedName', 'normalized_name'),
            Field::float('quantity', 'quantity'),
            Field::text('unit', 'unit')->nullable(),
            Field::bool('isChecked', 'is_checked'),
            Field::int('sortOrder', 'sort_order'),
            Field::timestamp('createdAt', 'created_at'),
            Field::timestamp('updatedAt', 'updated_at'),
            Field::timestamp('deletedAt', 'deleted_at')->nullable(),
        ];
    }
}
