<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use TamasLabs\LaravelExpenses\Tests\Fixtures\User;
use TamasLabs\LaravelExpenses\Tests\Support\AuthApi;
use TamasLabs\LaravelExpenses\Tests\Support\SyncApi;
use Workbench\Database\Seeders\DatabaseSeeder;

// Spec 08, 3.7 and 4: the development server's seed, on the tests' user model.

function devSeed(): void
{
    expect(Artisan::call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]))->toBe(0);
}

it('seeds a user who signs in and pulls the client\'s seed records', function (): void {
    devSeed();

    $user = User::query()->where('email', DatabaseSeeder::EMAIL)->sole();
    $answer = AuthApi::loginOk(DatabaseSeeder::EMAIL, DatabaseSeeder::PASSWORD);
    $page = AuthApi::ok(AuthApi::send('GET', 'expenses.sync.pull', token: AuthApi::accessToken($answer)), 200, 'protocol/pull-response');
    $records = SyncApi::records($page);

    expect($user->hasVerifiedEmail())->toBeTrue()
        ->and(array_column(SyncApi::group($records, 'category'), 'name'))->toBe(array_keys(DatabaseSeeder::CATEGORIES))
        ->and(array_column(SyncApi::group($records, 'category'), 'id')[0])->toBe('00000000-0000-4000-8100-000000000001')
        ->and(SyncApi::group($records, 'subcategory'))->toHaveCount(22)
        ->and(array_column(SyncApi::group($records, 'subcategory'), 'id')[21])->toBe('00000000-0000-4000-8200-000000000022')
        ->and(array_column(SyncApi::group($records, 'payment-method'), 'name'))->toBe(DatabaseSeeder::PAYMENT_METHODS)
        ->and(array_column(SyncApi::group($records, 'user'), 'id'))->toBe([$user->uuid]);
});

it('runs again without a change', function (): void {
    devSeed();
    $sequence = SyncApi::sequenceValue();

    devSeed();

    expect(User::query()->where('email', DatabaseSeeder::EMAIL)->count())->toBe(1)
        ->and(SyncApi::sequenceValue())->toBe($sequence);
});
