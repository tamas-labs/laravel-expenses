<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Database\Factories;

use TamasLabs\LaravelExpenses\Models\Subcategory;

/**
 * @extends SyncModelFactory<Subcategory>
 */
final class SubcategoryFactory extends SyncModelFactory
{
    protected $model = Subcategory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            ...$this->syncAttributes(),
            'category_id' => self::parent(CategoryFactory::new()),
            'name' => self::uniqueName(),
            'sort_order' => fake()->numberBetween(0, 100),
        ];
    }

    /**
     * One of the client's seed subcategories: the same UUID for every user.
     */
    public function seed(int $number = 1): static
    {
        return $this->state(['id' => sprintf('00000000-0000-4000-8200-%012d', $number)]);
    }
}
