<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $name
 * @property string $type
 * @property int $amount_minor
 * @property string $currency_id
 * @property string $expense_date
 * @property string|null $main_category_id
 * @property string|null $sub_category_id
 * @property string|null $payment_method_id
 * @property string|null $shop_display_name
 * @property string|null $normalized_shop
 * @property string|null $note
 * @property string $items
 * @property-read Currency $currency
 */
final class Expense extends SyncModel
{
    protected $table = 'expenses';

    protected $hidden = ['user_id', 'server_seq', 'synced_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
