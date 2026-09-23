<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Database\Factories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use TamasLabs\LaravelExpenses\Models\Currency;

/**
 * A currency besides the three the migration seeds.
 *
 * @extends Factory<Currency>
 */
final class CurrencyFactory extends Factory
{
    protected $model = Currency::class;

    private static int $code = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $createdAt = SyncModelFactory::timestamp();

        return [
            'id' => SyncModelFactory::uuid(),
            'code' => self::nextCode(),
            'name' => fake()->words(2, true),
            'symbol' => fake()->randomLetter(),
            'minor_unit' => 2,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'deleted_at' => null,
            'server_seq' => fn (): int => SyncModelFactory::nextServerSeq(),
            'synced_at' => CarbonImmutable::now('UTC'),
        ];
    }

    public function deleted(): static
    {
        return $this->state(fn (array $attributes): array => ['deleted_at' => $attributes['updated_at']]);
    }

    /**
     * XAA, XAB, …: ISO 4217 keeps the X codes out of national use.
     */
    private static function nextCode(): string
    {
        $n = self::$code++ % (26 * 26);

        return 'X'.\chr(65 + intdiv($n, 26)).\chr(65 + $n % 26);
    }
}
