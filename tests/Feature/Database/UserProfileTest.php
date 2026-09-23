<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use TamasLabs\LaravelExpenses\Database\Currencies;
use TamasLabs\LaravelExpenses\Tests\Fixtures\User;

// Spec 03, 3.10: the contract's user record on the host's users table.

it('gives a new user a lowercase UUID that matches the contract', function (): void {
    $user = User::factory()->createOne();

    expect($user->uuid)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/')
        ->and($user->fresh()?->uuid)->toBe($user->uuid);
});

it('keeps a UUID that is already set', function (): void {
    $user = User::factory()->createOne(['uuid' => '5f0c7a4e-2b8d-4c1e-9f3a-7d6b5e4c3a21']);

    expect($user->uuid)->toBe('5f0c7a4e-2b8d-4c1e-9f3a-7d6b5e4c3a21');
});

it('relates the user to their default currency', function (): void {
    $user = User::factory()->createOne(['default_currency_id' => Currencies::HUF]);

    expect($user->defaultCurrency?->code)->toBe('HUF');
});

it('reads the profile timestamps as UTC with milliseconds', function (): void {
    $user = User::factory()->createOne([
        'registered_at' => CarbonImmutable::parse('2026-09-14 08:30:00.123', 'UTC'),
        'profile_created_at' => CarbonImmutable::parse('2026-03-01T10:00:00.000+01:00'),
        'profile_updated_at' => CarbonImmutable::parse('2026-09-14 08:30:00.456', 'UTC'),
    ])->fresh();

    expect($user?->registered_at?->format('Y-m-d H:i:s.v e'))->toBe('2026-09-14 08:30:00.123 UTC')
        ->and($user?->profile_created_at?->format('Y-m-d H:i:s.v e'))->toBe('2026-03-01 09:00:00.000 UTC')
        ->and($user?->profile_updated_at?->format('Y-m-d H:i:s.v'))->toBe('2026-09-14 08:30:00.456');
});
