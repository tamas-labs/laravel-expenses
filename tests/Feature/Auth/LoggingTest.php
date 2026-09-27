<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use TamasLabs\LaravelExpenses\ExpensesServiceProvider;
use TamasLabs\LaravelExpenses\Tests\Support\AuthApi;
use TamasLabs\LaravelExpenses\Tests\Support\SyncApi;

// Spec 07, 6.

const LOGGED_SECRET = 'my very secret password';

beforeEach(function (): void {
    Notification::fake();

    // The trace keeps every argument, in full, as a debugging setup would.
    ini_set('zend.exception_ignore_args', '0');
    ini_set('zend.exception_string_param_max_len', '1000000');

    $log = tempnam(sys_get_temp_dir(), 'expenses-log-');
    Config::set('logging.channels.expenses_test', ['driver' => 'single', 'path' => $log, 'level' => 'debug']);
    Config::set('logging.default', 'expenses_test');
});

afterEach(function (): void {
    ini_restore('zend.exception_ignore_args');
    ini_restore('zend.exception_string_param_max_len');
    @unlink(Config::string('logging.channels.expenses_test.path'));
});

/**
 * A stack trace down to the HTTP kernel: the server's frames. Below it, the
 * test's own call holds the raw body, which a real request reads from
 * php://input instead.
 */
function serverFrames(string $trace): string
{
    $lines = explode("\n", $trace);
    // The log writes the trace JSON-escaped, with doubled backslashes.
    $kernel = array_key_first(array_filter($lines, static fn (string $line): bool => preg_match('/Http\\\\{1,2}Kernel->handle\(/', $line) === 1));

    expect($kernel)->not->toBeNull();

    return implode("\n", array_slice($lines, 0, ($kernel ?? 0) + 1));
}

it('logs a failed sign-in with the address hashed and the IP only', function (): void {
    AuthApi::registerOk(['email' => 'anna@example.com', 'password' => LOGGED_SECRET]);

    SyncApi::assertError(AuthApi::login('anna@example.com', LOGGED_SECRET.'!'), 401, 'invalid_credentials');

    $log = (string) file_get_contents(Config::string('logging.channels.expenses_test.path'));

    expect($log)->toContain('Expenses sign-in failed.', hash('sha256', 'anna@example.com'), '127.0.0.1');
    expect($log)->not->toContain('anna@example.com', LOGGED_SECRET);
});

it('keeps the password out of the log and the exception of an unexpected failure', function (): void {
    AuthApi::registerOk(['email' => 'anna@example.com', 'password' => LOGGED_SECRET]);
    /** @var list<Throwable> $reported */
    $reported = [];
    Exceptions::reportable(static function (Throwable $exception) use (&$reported): void {
        $reported[] = $exception;
    });

    Hash::swap(new class implements Hasher
    {
        /** @return array<string, mixed> */
        public function info(mixed $hashedValue): array
        {
            return [];
        }

        /** @param array<string, mixed> $options */
        public function make(#[SensitiveParameter] mixed $value, array $options = []): string
        {
            throw new RuntimeException('The hasher broke.');
        }

        /** @param array<string, mixed> $options */
        public function check(#[SensitiveParameter] mixed $value, mixed $hashedValue, array $options = []): bool
        {
            throw new RuntimeException('The hasher broke.');
        }

        /** @param array<string, mixed> $options */
        public function needsRehash(mixed $hashedValue, array $options = []): bool
        {
            return false;
        }
    });

    AuthApi::login('anna@example.com', LOGGED_SECRET)->assertServerError();

    expect($reported)->toHaveCount(1);

    $exception = $reported[0];

    $log = (string) file_get_contents(Config::string('logging.channels.expenses_test.path'));

    expect(serverFrames($exception->getTraceAsString()))->toContain('Accounts->login(');
    expect(serverFrames($exception->getTraceAsString()))->not->toContain(LOGGED_SECRET);
    expect(serverFrames($log))->toContain('The hasher broke.', 'Accounts->login(');
    expect(serverFrames($log))->not->toContain(LOGGED_SECRET);
});

it('never flashes the secrets of a request', function (): void {
    $flashed = (new ReflectionProperty(Handler::class, 'dontFlash'))->getValue(app(ExceptionHandler::class));

    expect($flashed)->toBeArray()->toContain(...ExpensesServiceProvider::SECRET_FIELDS);
});
