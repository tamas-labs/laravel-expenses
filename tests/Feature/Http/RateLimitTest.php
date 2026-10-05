<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Cache\RateLimiting\Unlimited;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use TamasLabs\LaravelExpenses\Http\RateLimits;
use TamasLabs\LaravelExpenses\Tests\Support\AuthApi;
use TamasLabs\LaravelExpenses\Tests\Support\Records;
use TamasLabs\LaravelExpenses\Tests\Support\SyncApi;

use function Pest\Laravel\getJson;

// Spec 08, 3.1 and 4: the general rate limits.

beforeEach(function (): void {
    SyncApi::startSequenceAt(1_000_000);
});

it('refuses the push above its limit with 429 and Retry-After, counted per user, not per IP', function (): void {
    Config::set('expenses.rate_limits.push', 2);
    [$anna, $bela] = [SyncApi::userWithProfile(), SyncApi::userWithProfile()];

    SyncApi::pushOk($anna, ['category' => [Records::make('category')]]);
    SyncApi::pushOk($anna, ['category' => [Records::make('category')]]);
    $refused = SyncApi::push($anna, ['category' => [Records::make('category')]]);

    SyncApi::assertError($refused, 429, 'too_many_requests');
    expect((int) $refused->headers->get('Retry-After'))->toBeGreaterThan(0)->toBeLessThanOrEqual(60);

    SyncApi::pushOk($bela, ['category' => [Records::make('category')]]);
});

it('limits the pull on its own, higher count', function (): void {
    Config::set('expenses.rate_limits.push', 1);
    Config::set('expenses.rate_limits.pull', 3);
    $user = SyncApi::userWithProfile();

    SyncApi::pushOk($user, ['category' => [Records::make('category')]]);

    foreach (range(1, 3) as $attempt) {
        SyncApi::pullOk($user);
    }

    SyncApi::assertError(SyncApi::pull($user), 429, 'too_many_requests');
});

it('limits the profile endpoints together', function (): void {
    Config::set('expenses.rate_limits.me', 2);
    $token = AuthApi::accessToken(AuthApi::registerOk());

    AuthApi::me($token)->assertOk();
    AuthApi::me($token)->assertOk();

    SyncApi::assertError(AuthApi::me($token), 429, 'too_many_requests');
    SyncApi::assertError(AuthApi::send('DELETE', 'expenses.me.destroy', ['password' => AuthApi::PASSWORD], $token), 429, 'too_many_requests');
});

it('lets the host replace a limiter', function (): void {
    RateLimiter::for(RateLimits::PULL, static fn (): Limit => Limit::perMinute(1)->by('everyone'));
    [$anna, $bela] = [SyncApi::userWithProfile(), SyncApi::userWithProfile()];

    SyncApi::pullOk($anna);

    SyncApi::assertError(SyncApi::pull($bela), 429, 'too_many_requests');
});

it('keeps a limiter the host registered before the package', function (): void {
    $hosts = static fn (): Limit => Limit::none();
    RateLimiter::for(RateLimits::ME, $hosts);

    RateLimits::register();

    $limiter = RateLimiter::limiter(RateLimits::ME);

    expect($limiter === null ? null : $limiter(Request::create('/')))->toBeInstanceOf(Unlimited::class);
});

it('leaves the other routes\' 429 alone', function (): void {
    RateLimiter::for('hosts', static fn (): Limit => Limit::perMinute(1));
    Route::middleware(RateLimits::middleware('hosts'))->get('host-route', static fn (): string => 'ok');

    getJson('host-route')->assertOk();
    $response = getJson('host-route');

    expect($response->status())->toBe(429)
        ->and($response->json('error.code'))->toBeNull();
});
