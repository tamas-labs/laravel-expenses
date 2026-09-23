<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Tests\Fixtures;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    protected $model = User::class;

    private static ?string $password = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => Str::lower(Str::random(12)).'@example.com',
            'password' => self::$password ??= Hash::make('password'),
        ];
    }
}
