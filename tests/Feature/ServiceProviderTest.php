<?php

declare(strict_types=1);

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Auth\User as FoundationUser;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use TamasLabs\LaravelExpenses\Auth\Accounts;
use TamasLabs\LaravelExpenses\Auth\AccountStore;
use TamasLabs\LaravelExpenses\Auth\DeleteAccount;
use TamasLabs\LaravelExpenses\Auth\DeviceSessions;
use TamasLabs\LaravelExpenses\Contract\ContractValidator;
use TamasLabs\LaravelExpenses\ExpensesServiceProvider;
use TamasLabs\LaravelExpenses\HasExpensesProfile;
use TamasLabs\LaravelExpenses\Http\Middleware\EnsureContractVersion;
use TamasLabs\LaravelExpenses\Http\Middleware\EnsureEmailIsVerified;
use TamasLabs\LaravelExpenses\Sync\PullHandler;
use TamasLabs\LaravelExpenses\Sync\PushHandler;
use TamasLabs\LaravelExpenses\Sync\SyncStore;

it('loads the service provider', function (): void {
    expect(app()->getProviders(ExpensesServiceProvider::class))->toHaveCount(1);
});

it('merges the package config under the expenses key', function (): void {
    expect(config('expenses'))->toBeArray();
});

it('publishes the config file with the expenses-config tag', function (): void {
    $target = config_path('expenses.php');
    File::delete($target);

    try {
        $exitCode = Artisan::call('vendor:publish', ['--tag' => 'expenses-config']);

        expect($exitCode)->toBe(0)
            ->and($target)->toBeFile()
            ->and(File::get($target))->toBe(File::get(dirname(__DIR__, 2).'/config/expenses.php'));
    } finally {
        File::delete($target);
    }
});

it('loads the package migrations', function (): void {
    expect(app('migrator')->paths())->toContain(dirname(__DIR__, 2).'/database/migrations');
});

it('publishes the migrations under their own names with the expenses-migrations tag', function (): void {
    $source = dirname(__DIR__, 2).'/database/migrations';

    expect(ServiceProvider::pathsToPublish(ExpensesServiceProvider::class, 'expenses-migrations'))
        ->toBe([$source => database_path('migrations')]);
});

it('binds the contract validator as a singleton', function (): void {
    expect(app(ContractValidator::class))->toBe(app(ContractValidator::class));
});

it('registers the contract version middleware alias', function (): void {
    expect(app(Router::class)->getMiddleware())->toHaveKey('expenses.contract', EnsureContractVersion::class);
});

const OPEN_ROUTE = ['api', 'expenses.contract'];

const TOKEN_ROUTE = [...OPEN_ROUTE, 'auth:sanctum', CheckAbilities::class.':expenses:access'];

const VERIFIED_ROUTE = [...TOKEN_ROUTE, EnsureEmailIsVerified::class];

it('registers the routes under the configured prefix, behind the configured middleware and the package\'s authentication', function (string $name, string $method, string $uri, array $middleware): void {
    $route = app(Router::class)->getRoutes()->getByName($name);

    expect($route)->not->toBeNull()
        ->and($route?->methods())->toContain($method)
        ->and($route?->uri())->toBe($uri)
        ->and($route?->gatherMiddleware())->toBe($middleware);
})->with([
    ['expenses.sync.push', 'POST', 'api/expenses/sync/push', VERIFIED_ROUTE],
    ['expenses.sync.pull', 'GET', 'api/expenses/sync/pull', VERIFIED_ROUTE],
    ['expenses.me.update', 'PATCH', 'api/expenses/me', VERIFIED_ROUTE],
    ['expenses.me.show', 'GET', 'api/expenses/me', TOKEN_ROUTE],
    ['expenses.me.destroy', 'DELETE', 'api/expenses/me', TOKEN_ROUTE],
    ['expenses.auth.logout', 'POST', 'api/expenses/auth/logout', TOKEN_ROUTE],
    ['expenses.auth.email.resend', 'POST', 'api/expenses/auth/email/resend', TOKEN_ROUTE],
    ['expenses.auth.register', 'POST', 'api/expenses/auth/register', OPEN_ROUTE],
    ['expenses.auth.login', 'POST', 'api/expenses/auth/login', OPEN_ROUTE],
    ['expenses.auth.refresh', 'POST', 'api/expenses/auth/refresh', OPEN_ROUTE],
    ['expenses.auth.password.forgot', 'POST', 'api/expenses/auth/password/forgot', OPEN_ROUTE],
    ['expenses.auth.password.reset', 'POST', 'api/expenses/auth/password/reset', OPEN_ROUTE],
    // Opened from a mail in a browser: no contract header, the signature authorizes it.
    ['expenses.auth.email.verify', 'GET', 'api/expenses/auth/email/verify/{uuid}/{hash}', []],
]);

it('runs the contract version middleware before any other, authentication included', function (): void {
    // The priority goes onto the HTTP kernel when it is resolved.
    app(Kernel::class);

    expect(app(Router::class)->middlewarePriority[0] ?? null)->toBe(EnsureContractVersion::class);
});

it('binds the sync services as singletons', function (string $class): void {
    expect(app($class))->toBe(app($class));
})->with([SyncStore::class, PushHandler::class, PullHandler::class]);

it('refuses to boot with a user model that lacks what the account endpoints need', function (): void {
    Config::set('expenses.user_model', FoundationUser::class);

    expect(fn () => (new ExpensesServiceProvider(app()))->boot())->toThrow(
        LogicException::class,
        'The user model '.FoundationUser::class.' (expenses.user_model) must use the '.HasExpensesProfile::class.' trait, use the '.HasApiTokens::class.' trait, implement '.MustVerifyEmail::class.'.',
    );
});

it('refuses to boot without the links of the account mails', function (string $key, ?string $value, string $message): void {
    Config::set($key, $value);

    expect(fn () => (new ExpensesServiceProvider(app()))->boot())->toThrow(InvalidArgumentException::class, $message);
})->with([
    'no reset link' => ['expenses.auth.password_reset_url', null, 'The expenses.auth.password_reset_url setting is required; set EXPENSES_PASSWORD_RESET_URL.'],
    'a reset link without the token' => ['expenses.auth.password_reset_url', 'https://app.example.com/reset', 'must hold the {token} placeholder'],
    'no verified page' => ['expenses.auth.email_verified_url', ' ', 'The expenses.auth.email_verified_url setting is required; set EXPENSES_EMAIL_VERIFIED_URL.'],
]);

it('builds the mails\' links unless the host already does', function (): void {
    $verify = VerifyEmail::$createUrlCallback;
    $reset = ResetPassword::$createUrlCallback;
    $hosts = static fn (): string => 'https://host.example.com';

    try {
        expect($verify)->not->toBeNull()
            ->and($reset)->not->toBeNull();

        VerifyEmail::createUrlUsing($hosts);
        ResetPassword::createUrlUsing($hosts);
        (new ExpensesServiceProvider(app()))->boot();

        expect(VerifyEmail::$createUrlCallback)->toBe($hosts)
            ->and(ResetPassword::$createUrlCallback)->toBe($hosts);
    } finally {
        VerifyEmail::$createUrlCallback = $verify;
        ResetPassword::$createUrlCallback = $reset;
    }
});

it('binds the account services as singletons', function (string $class): void {
    expect(app($class))->toBe(app($class));
})->with([AccountStore::class, DeviceSessions::class, Accounts::class, DeleteAccount::class]);
