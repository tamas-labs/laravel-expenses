<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Mapping\Mappers;

use TamasLabs\LaravelExpenses\Mapping\Field;
use TamasLabs\LaravelExpenses\Mapping\RecordMapper;

final class PaymentMethodMapper extends RecordMapper
{
    /**
     * @return list<Field>
     */
    protected function fields(): array
    {
        return [
            Field::uuid('id', 'id'),
            Field::text('name', 'name'),
            Field::int('sortOrder', 'sort_order')->nullable(),
            Field::text('color', 'color')->nullable(),
            Field::text('icon', 'icon')->nullable(),
            Field::timestamp('createdAt', 'created_at'),
            Field::timestamp('updatedAt', 'updated_at'),
            Field::timestamp('deletedAt', 'deleted_at')->nullable(),
        ];
    }
}
