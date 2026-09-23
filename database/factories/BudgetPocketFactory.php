<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Database\Factories;

use Illuminate\Support\Facades\DB;
use TamasLabs\LaravelExpenses\Models\BudgetPocket;
use TamasLabs\LaravelExpenses\Support\PackageConfig;

/**
 * @extends SyncModelFactory<BudgetPocket>
 */
final class BudgetPocketFactory extends SyncModelFactory
{
    protected $model = BudgetPocket::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            ...$this->syncAttributes(),
            'name' => self::uniqueName(),
            'start_day' => fake()->numberBetween(1, 28),
            'budget_amount_minor' => fake()->numberBetween(1, 1000) * 10000,
            'color' => null,
        ];
    }

    /**
     * Links the pocket to new categories of the same user (the pivot rows).
     */
    public function withCategories(int $count = 1): static
    {
        return $this->afterCreating(function (BudgetPocket $pocket) use ($count): void {
            foreach (CategoryFactory::new()->ownedBy($pocket->user_id)->createMany($count) as $category) {
                DB::table(PackageConfig::table('budget_pocket_categories'))->insert([
                    'user_id' => $pocket->user_id,
                    'budget_pocket_id' => $pocket->id,
                    'category_id' => $category->id,
                ]);
            }
        });
    }
}
