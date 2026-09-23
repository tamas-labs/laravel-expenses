<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Database\Factories;

use TamasLabs\LaravelExpenses\Database\Currencies;
use TamasLabs\LaravelExpenses\Models\ProductPrice;

/**
 * A manually recorded price (no source expense).
 *
 * @extends SyncModelFactory<ProductPrice>
 */
final class ProductPriceFactory extends SyncModelFactory
{
    protected $model = ProductPrice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $shop = fake()->company();

        return [
            ...$this->syncAttributes(),
            'product_id' => self::parent(ProductFactory::new()),
            'shop_display_name' => mb_substr($shop, 0, 80),
            'normalized_shop' => self::normalize($shop),
            'unit_price_minor' => fake()->numberBetween(1, 5000) * 100,
            'currency_id' => Currencies::HUF,
            'quantity' => null,
            'unit' => null,
            'observed_at' => fake()->date(),
            'source' => 'manual',
            'source_expense_id' => null,
        ];
    }
}
