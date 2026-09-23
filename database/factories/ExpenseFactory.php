<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Database\Factories;

use TamasLabs\LaravelExpenses\Database\Currencies;
use TamasLabs\LaravelExpenses\Models\Expense;

/**
 * An expense with one item, its amount the item's total.
 *
 * @extends SyncModelFactory<Expense>
 */
final class ExpenseFactory extends SyncModelFactory
{
    protected $model = Expense::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = self::uniqueName();
        $unitPriceMinor = fake()->numberBetween(1, 5000) * 100;
        $quantity = fake()->numberBetween(1, 5);
        $shop = fake()->company();

        return [
            ...$this->syncAttributes(),
            'name' => $name,
            'type' => 'expense',
            'amount_minor' => $unitPriceMinor * $quantity,
            'currency_id' => Currencies::HUF,
            'expense_date' => fake()->date(),
            'main_category_id' => null,
            'sub_category_id' => null,
            'payment_method_id' => null,
            'shop_display_name' => mb_substr($shop, 0, 80),
            'normalized_shop' => self::normalize($shop),
            'note' => null,
            'items' => [
                (object) [
                    'id' => self::uuid(),
                    'name' => $name,
                    'normalizedName' => self::normalize($name),
                    'itemCategory' => null,
                    'necessityLevel' => 'essential',
                    'unitPriceMinor' => $unitPriceMinor,
                    'quantity' => $quantity,
                    'unit' => null,
                    'totalAmountMinor' => $unitPriceMinor * $quantity,
                ],
            ],
        ];
    }
}
