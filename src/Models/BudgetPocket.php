<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Models;

/**
 * @property string $name
 * @property int $start_day
 * @property int $budget_amount_minor
 * @property string|null $color
 */
final class BudgetPocket extends SyncModel
{
    /**
     * The pocket's categories, from the `budget_pocket_categories` pivot.
     *
     * Not an attribute: there are no relations between user rows, so the
     * storage layer loads it with a query of its own. `null` until loaded,
     * which the mapper refuses to send rather than sending `[]`.
     *
     * @var list<string>|null
     */
    public ?array $categoryIds = null;

    protected $table = 'budget_pockets';

    protected $hidden = ['user_id', 'server_seq', 'synced_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_day' => 'integer',
            'budget_amount_minor' => 'integer',
        ];
    }
}
