<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use TamasLabs\LaravelExpenses\Database\Casts\Double;

/**
 * @property string $product_id
 * @property string $shop_display_name
 * @property string $normalized_shop
 * @property int $unit_price_minor
 * @property string $currency_id
 * @property float|null $quantity
 * @property string|null $unit
 * @property string $observed_at
 * @property string $source
 * @property string|null $source_expense_id
 * @property-read Currency $currency
 */
final class ProductPrice extends SyncModel
{
    protected $table = 'product_prices';

    protected $hidden = ['user_id', 'server_seq', 'synced_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price_minor' => 'integer',
            'quantity' => Double::class,
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
