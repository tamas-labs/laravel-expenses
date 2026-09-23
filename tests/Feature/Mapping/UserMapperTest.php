<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use TamasLabs\LaravelExpenses\Database\Currencies;
use TamasLabs\LaravelExpenses\Registry\ResourceRegistry;
use TamasLabs\LaravelExpenses\Tests\Fixtures\UserFactory;
use TamasLabs\LaravelExpenses\Tests\Support\ContractSchema;
use TamasLabs\LaravelExpenses\Tests\TestCase;

// Spec 04, 3.6: the user record on the host's users table.

it('maps the record onto the users table columns', function (): void {
    $attributes = app(ResourceRegistry::class)->mapper('user')->toAttributes(ContractSchema::example('user'))->attributes;

    expect(array_keys($attributes))->toBe([
        'uuid',
        'email',
        'name',
        'default_currency_id',
        'registered_at',
        'profile_created_at',
        'profile_updated_at',
    ])
        ->and($attributes['uuid'])->toBe('7c9e6679-7425-40de-944b-e07fc1f90ae7')
        ->and($attributes['name'])->toBe('Anna')
        ->and($attributes['default_currency_id'])->toBe(Currencies::HUF)
        ->and($attributes['profile_created_at'])->toEqual(CarbonImmutable::parse('2026-03-01 10:00:00.000', 'UTC'));
});

it('sends the profile, not the table key or Laravel\'s timestamps', function (): void {
    $user = UserFactory::new()->createOne([
        'uuid' => '5f0c7a4e-2b8d-4c1e-9f3a-7d6b5e4c3a21',
        'name' => 'Béla',
        'email' => 'bela@example.com',
        'default_currency_id' => null,
        'registered_at' => CarbonImmutable::parse('2026-09-14 08:30:00.123', 'UTC'),
        'profile_created_at' => CarbonImmutable::parse('2026-09-01 08:00:00.000', 'UTC'),
        'profile_updated_at' => CarbonImmutable::parse('2026-09-14 08:30:00.456', 'UTC'),
    ])->fresh();
    assert($user !== null);

    $payload = app(ResourceRegistry::class)->mapper('user')->toPayload($user);

    expect($payload)->toBe([
        'id' => '5f0c7a4e-2b8d-4c1e-9f3a-7d6b5e4c3a21',
        'email' => 'bela@example.com',
        'displayName' => 'Béla',
        'defaultCurrencyId' => null,
        'registeredAt' => '2026-09-14T08:30:00.123Z',
        'createdAt' => '2026-09-01T08:00:00.000Z',
        'updatedAt' => '2026-09-14T08:30:00.456Z',
    ]);
    TestCase::assertMatchesContract('user', $payload);
});

it('refuses to send a user without a profile createdAt', function (): void {
    $user = UserFactory::new()->createOne([
        'registered_at' => CarbonImmutable::now('UTC'),
        'profile_updated_at' => CarbonImmutable::now('UTC'),
    ])->fresh();
    assert($user !== null);

    expect($user->profile_created_at)->toBeNull();

    app(ResourceRegistry::class)->mapper('user')->toPayload($user);
})->throws(LogicException::class, 'profile_created_at');
