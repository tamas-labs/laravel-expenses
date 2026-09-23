<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Mapping\Mappers;

use TamasLabs\LaravelExpenses\Mapping\Field;
use TamasLabs\LaravelExpenses\Mapping\RecordMapper;

final class ExpenseMapper extends RecordMapper
{
    /**
     * @return list<Field>
     */
    protected function fields(): array
    {
        return [
            Field::uuid('id', 'id'),
            Field::text('name', 'name'),
            Field::text('type', 'type'),
            Field::int('amountMinor', 'amount_minor'),
            Field::uuid('currencyId', 'currency_id'),
            Field::date('expenseDate', 'expense_date'),
            Field::uuid('mainCategoryId', 'main_category_id')->nullable(),
            Field::uuid('subCategoryId', 'sub_category_id')->nullable(),
            Field::uuid('paymentMethodId', 'payment_method_id')->nullable(),
            Field::text('shopDisplayName', 'shop_display_name')->nullable(),
            Field::text('normalizedShop', 'normalized_shop')->nullable(),
            Field::text('note', 'note')->nullable(),
            Field::json('items', 'items'),
            Field::timestamp('createdAt', 'created_at'),
            Field::timestamp('updatedAt', 'updated_at'),
            Field::timestamp('deletedAt', 'deleted_at')->nullable(),
        ];
    }
}
