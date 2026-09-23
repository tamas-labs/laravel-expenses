<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Database\Factories;

use stdClass;
use TamasLabs\LaravelExpenses\Models\Product;

/**
 * @extends SyncModelFactory<Product>
 */
final class ProductFactory extends SyncModelFactory
{
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = self::uniqueName();

        return [
            ...$this->syncAttributes(),
            'normalized_name' => self::normalize($name),
            'display_name' => $name,
            'main_category_id' => null,
            'sub_category_id' => null,
            'default_unit' => null,
            'barcode' => fake()->ean13(),
            'off_data' => null,
            'off_category' => null,
            'off_last_synced_at' => null,
            'user_note' => null,
            'user_rating' => null,
            'user_status' => null,
            'preferred_shop' => null,
            'user_tags' => [],
            'purchase_reminder' => false,
            'custom_fields' => new stdClass,
        ];
    }
}
