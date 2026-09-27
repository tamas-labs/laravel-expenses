<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Exceptions\MissingAbilityException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use TamasLabs\LaravelExpenses\Auth\Accounts;
use TamasLabs\LaravelExpenses\Auth\AccountStore;
use TamasLabs\LaravelExpenses\Auth\AuthError;
use TamasLabs\LaravelExpenses\Auth\AuthRequest;
use TamasLabs\LaravelExpenses\Auth\DeleteAccount;
use TamasLabs\LaravelExpenses\Auth\DeviceSessions;
use TamasLabs\LaravelExpenses\Auth\EmailVerification;
use TamasLabs\LaravelExpenses\Auth\Passwords;
use TamasLabs\LaravelExpenses\Auth\UserModel;
use TamasLabs\LaravelExpenses\Contract\ContractValidator;
use TamasLabs\LaravelExpenses\Http\Middleware\EnsureContractVersion;
use TamasLabs\LaravelExpenses\Registry\ResourceRegistry;
use TamasLabs\LaravelExpenses\Rules\DomainValidator;
use TamasLabs\LaravelExpenses\Support\PackageConfig;
use TamasLabs\LaravelExpenses\Sync\PullHandler;
use TamasLabs\LaravelExpenses\Sync\PushHandler;
use TamasLabs\LaravelExpenses\Sync\SyncStore;

final class ExpensesServiceProvider extends ServiceProvider
{
    /**
     * Request fields never flashed to the session nor kept with an error
     * (spec 07, 6).
     */
    public const array SECRET_FIELDS = ['password', 'token', 'accessToken', 'refreshToken'];

    public function register(): void
    {
        $this->mergeConfigFrom(self::configPath(), 'expenses');

        $this->app->singleton(ContractValidator::class);
        $this->app->singleton(ResourceRegistry::class);
        $this->app->singleton(DomainValidator::class);
        $this->app->singleton(SyncStore::class);
        $this->app->singleton(PushHandler::class);
        $this->app->singleton(PullHandler::class);
        $this->app->singleton(AccountStore::class);
        $this->app->singleton(DeviceSessions::class);
        $this->app->singleton(AuthRequest::class);
        $this->app->singleton(Accounts::class);
        $this->app->singleton(Passwords::class);
        $this->app->singleton(EmailVerification::class);
        $this->app->singleton(DeleteAccount::class);
    }

    /**
     * @throws \LogicException|\InvalidArgumentException When the user model or the auth settings are not ready.
     */
    public function boot(): void
    {
        // A host missing a piece hears it at once, not on the first sign-in.
        UserModel::check();
        PackageConfig::passwordResetUrl();
        PackageConfig::emailVerifiedUrl();

        $this->app->make(Router::class)->aliasMiddleware('expenses.contract', EnsureContractVersion::class);

        // Ahead of the priority list, so it wraps authentication too: even a
        // 401 tells the client the server's contract version.
        $this->callAfterResolving(HttpKernel::class, static function (mixed $kernel): void {
            if ($kernel instanceof Kernel) {
                $kernel->prependToMiddlewarePriority(EnsureContractVersion::class);
            }
        });

        $this->callAfterResolving(ExceptionHandler::class, static function (mixed $handler): void {
            if ($handler instanceof Handler) {
                self::configureExceptions($handler);
            }
        });

        // After every provider booted: the links of the mails are the
        // package's only when the host did not set its own.
        $this->app->booted(static function (): void {
            if (VerifyEmail::$createUrlCallback === null) {
                VerifyEmail::createUrlUsing(EmailVerification::url(...));
            }

            if (ResetPassword::$createUrlCallback === null) {
                ResetPassword::createUrlUsing(Passwords::url(...));
            }
        });

        $this->loadMigrationsFrom(self::migrationsPath());
        $this->loadRoutesFrom(\dirname(__DIR__).'/routes/api.php');
        $this->loadJsonTranslationsFrom(\dirname(__DIR__).'/lang');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                self::configPath() => $this->app->configPath('expenses.php'),
            ], 'expenses-config');

            // Published under their own names: the migrator keys migrations by
            // name and lets the application's copy win, so a customised copy
            // replaces the package's instead of running next to it.
            $this->publishes([
                self::migrationsPath() => $this->app->databasePath('migrations'),
            ], 'expenses-migrations');
        }
    }

    /**
     * The package's routes answer a missing token and a missing ability with
     * the contract's error envelope (401 `unauthenticated`, 403 `forbidden`),
     * never with a redirect or Laravel's default body; the secrets of a
     * request are never flashed.
     */
    private static function configureExceptions(Handler $handler): void
    {
        $handler->dontFlash(self::SECRET_FIELDS);

        $handler->renderable(static fn (AuthenticationException $exception, Request $request): ?JsonResponse => self::ownsRoute($request)
            ? AuthError::unauthenticated()->render()
            : null);

        // Laravel turns the authorization exception into this one first.
        $handler->renderable(static fn (AccessDeniedHttpException $exception, Request $request): ?JsonResponse => self::ownsRoute($request) && $exception->getPrevious() instanceof MissingAbilityException
            ? AuthError::forbidden()->render()
            : null);
    }

    private static function ownsRoute(Request $request): bool
    {
        $route = $request->route();

        return $route instanceof Route && str_starts_with((string) $route->getName(), 'expenses.');
    }

    private static function configPath(): string
    {
        return \dirname(__DIR__).'/config/expenses.php';
    }

    private static function migrationsPath(): string
    {
        return \dirname(__DIR__).'/database/migrations';
    }
}
