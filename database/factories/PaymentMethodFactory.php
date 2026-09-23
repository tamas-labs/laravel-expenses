<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Database\Factories;

use TamasLabs\LaravelExpenses\Models\PaymentMethod;

/**
 * @extends SyncModelFactory<PaymentMethod>
 */
final class PaymentMethodFactory extends SyncModelFactory
{
    protected $model = PaymentMethod::class;

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
     * One of the client's seed payment methods: the same UUID for every user.
     */
    public function seed(int $number = 1): static
    {
        return $this->state(['id' => sprintf('00000000-0000-4000-8300-%012d', $number)]);
    }
}
