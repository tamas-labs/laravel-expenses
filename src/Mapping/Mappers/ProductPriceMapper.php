<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Mapping\Mappers;

use TamasLabs\LaravelExpenses\Mapping\Field;
use TamasLabs\LaravelExpenses\Mapping\RecordMapper;

final class ProductPriceMapper extends RecordMapper
{
    /**
     * @return list<Field>
     */
    protected function fields(): array
    {
        return [
            Field::uuid('id', 'id'),
            Field::uuid('productId', 'product_id'),
            Field::text('shopDisplayName', 'shop_display_name'),
            Field::text('normalizedShop', 'normalized_shop'),
            Field::int('unitPriceMinor', 'unit_price_minor'),
            Field::uuid('currencyId', 'currency_id'),
            Field::float('quantity', 'quantity')->nullable(),
            Field::text('unit', 'unit')->nullable(),
            Field::date('observedAt', 'observed_at'),
            Field::text('source', 'source'),
            Field::uuid('sourceExpenseId', 'source_expense_id')->nullable(),
            Field::timestamp('createdAt', 'created_at'),
            Field::timestamp('updatedAt', 'updated_at'),
            Field::timestamp('deletedAt', 'deleted_at')->nullable(),
        ];
    }
}
