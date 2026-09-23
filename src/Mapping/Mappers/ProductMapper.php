<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Mapping\Mappers;

use TamasLabs\LaravelExpenses\Mapping\Field;
use TamasLabs\LaravelExpenses\Mapping\RecordMapper;

final class ProductMapper extends RecordMapper
{
    /**
     * @return list<Field>
     */
    protected function fields(): array
    {
        return [
            Field::uuid('id', 'id'),
            Field::text('normalizedName', 'normalized_name'),
            Field::text('displayName', 'display_name'),
            Field::uuid('mainCategoryId', 'main_category_id')->nullable(),
            Field::uuid('subCategoryId', 'sub_category_id')->nullable(),
            Field::text('defaultUnit', 'default_unit')->nullable(),
            Field::text('barcode', 'barcode')->nullable(),
            Field::json('offData', 'off_data')->nullable(),
            Field::text('offCategory', 'off_category')->nullable(),
            Field::timestamp('offLastSyncedAt', 'off_last_synced_at')->nullable(),
            Field::text('userNote', 'user_note')->nullable(),
            Field::int('userRating', 'user_rating')->nullable(),
            Field::text('userStatus', 'user_status')->nullable(),
            Field::text('preferredShop', 'preferred_shop')->nullable(),
            Field::json('userTags', 'user_tags'),
            Field::bool('purchaseReminder', 'purchase_reminder'),
            Field::json('customFields', 'custom_fields'),
            Field::timestamp('createdAt', 'created_at'),
            Field::timestamp('updatedAt', 'updated_at'),
            Field::timestamp('deletedAt', 'deleted_at')->nullable(),
        ];
    }
}
