<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use TamasLabs\LaravelExpenses\Auth\AccountStore;
use TamasLabs\LaravelExpenses\Auth\RefreshToken;
use TamasLabs\LaravelExpenses\Database\Casts\UtcDateTime;
use TamasLabs\LaravelExpenses\Support\PackageConfig;
use TamasLabs\LaravelExpenses\Tests\Support\AuthApi;

// Spec 08, 3.4 and 4: the refresh tokens go a week after they expired, with
// Laravel's model:prune.

it('deletes the tokens expired longer ago than the grace period, used or not', function (): void {
    AuthApi::refreshOk(AuthApi::refreshToken(AuthApi::registerOk()));

    $table = DB::table(PackageConfig::table(AccountStore::REFRESH_TOKENS));
    [$used, $current] = $table->orderBy('id')->pluck('id')->all();
    $expired = static fn (int $days): string => CarbonImmutable::now('UTC')->subDays($days)->format(UtcDateTime::FORMAT);

    (clone $table)->where('id', $used)->update(['expires_at' => $expired(RefreshToken::GRACE_DAYS + 1)]);
    (clone $table)->where('id', $current)->update(['expires_at' => $expired(RefreshToken::GRACE_DAYS - 1)]);

    expect(Artisan::call('model:prune', ['--model' => [RefreshToken::class]]))->toBe(0)
        ->and((clone $table)->pluck('id')->all())->toBe([$current]);
});

it('keeps the tokens that have not expired', function (): void {
    AuthApi::refreshOk(AuthApi::refreshToken(AuthApi::registerOk()));

    Artisan::call('model:prune', ['--model' => [RefreshToken::class]]);

    expect(DB::table(PackageConfig::table(AccountStore::REFRESH_TOKENS))->count())->toBe(2);
});
