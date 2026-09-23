<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Database\Factories;

use TamasLabs\LaravelExpenses\Models\Category;

/**
 * @extends SyncModelFactory<Category>
 */
final class CategoryFactory extends SyncModelFactory
{
    protected $model = Category::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            ...$this->syncAttributes(),
            'name' => self::uniqueName(),
            'sort_order' => fake()->numberBetween(0, 100),
            'color' => fake()->hexColor(),
            'icon' => null,
        ];
    }

    /**
     * One of the client's seed categories: the same UUID for every user.
     */
    public function seed(int $number = 1): static
    {
        return $this->state(['id' => sprintf('00000000-0000-4000-8100-%012d', $number)]);
    }
}
