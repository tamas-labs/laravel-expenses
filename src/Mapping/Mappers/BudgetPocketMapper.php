<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Mapping\Mappers;

use Illuminate\Database\Eloquent\Model;
use LogicException;
use TamasLabs\LaravelExpenses\Mapping\Field;
use TamasLabs\LaravelExpenses\Mapping\MappedRecord;
use TamasLabs\LaravelExpenses\Mapping\RecordMapper;
use TamasLabs\LaravelExpenses\Models\BudgetPocket;

/**
 * The pocket's `categoryIds` live in the `budget_pocket_categories` pivot:
 * they come in among the {@see MappedRecord::$links} and go out from
 * {@see BudgetPocket::$categoryIds}, which the storage layer loads.
 */
final class BudgetPocketMapper extends RecordMapper
{
    /**
     * @return list<Field>
     */
    protected function fields(): array
    {
        return [
            Field::uuid('id', 'id'),
            Field::text('name', 'name'),
            Field::int('startDay', 'start_day'),
            Field::int('budgetAmountMinor', 'budget_amount_minor'),
            Field::text('color', 'color')->nullable(),
            Field::links('categoryIds', 'budget_pocket_categories'),
            Field::timestamp('createdAt', 'created_at'),
            Field::timestamp('updatedAt', 'updated_at'),
            Field::timestamp('deletedAt', 'deleted_at')->nullable(),
        ];
    }

    /**
     * @return list<string>|null
     */
    protected function links(Model $model, Field $field): ?array
    {
        if (! $model instanceof BudgetPocket) {
            throw new LogicException(sprintf('%s maps budget pockets; got %s.', self::class, $model::class));
        }

        return $model->categoryIds;
    }
}
