<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Monolog\Formatter\JsonFormatter;
use TamasLabs\LaravelExpenses\Support\Json;
use TamasLabs\LaravelExpenses\Tests\Support\AuthApi;
use TamasLabs\LaravelExpenses\Tests\Support\LogFile;
use TamasLabs\LaravelExpenses\Tests\Support\Records;
use TamasLabs\LaravelExpenses\Tests\Support\SyncApi;

// Spec 08, 3.6 and 4: the push summary in the log.

const SECRET_NAME = 'Titkos kategória 4711';

beforeEach(function (): void {
    SyncApi::startSequenceAt(1_000_000);
    LogFile::capture();
});

afterEach(function (): void {
    LogFile::forget();
    @unlink(Config::string('logging.channels.expenses_own.path', ''));
});

it('logs every push: the user, the device, the counts per resource and status, the time', function (): void {
    $answer = AuthApi::registerOk();
    $user = AuthApi::user($answer);
    $stored = Records::stored('category', $user);
    $stale = clone $stored;
    $stale->updatedAt = '2020-01-01T00:00:00.000Z';

    AuthApi::send('POST', 'expenses.sync.push', ['records' => [
        'category' => [Records::make('category'), Records::make('category'), $stale],
        'payment-method' => [Records::make('payment-method')],
    ]], AuthApi::accessToken($answer))->assertOk();

    $line = LogFile::line('Expenses push.');

    expect($line['level_name'] ?? null)->toBe('INFO')
        ->and(LogFile::context($line))->toMatchArray([
            'user' => $user->uuid,
            'device' => AuthApi::DEVICE,
            'records' => ['category' => ['accepted' => 2, 'stale' => 1], 'payment-method' => ['accepted' => 1]],
        ])
        ->and(LogFile::context($line)['durationMs'] ?? null)->toBeInt();
});

it('warns of rejected records with the paths and keywords of their issues, never their values', function (): void {
    $user = SyncApi::userWithProfile();
    $taken = Records::stored('category', $user, ['name' => SECRET_NAME]);
    $clash = Records::make('category', ['name' => SECRET_NAME, 'color' => '#123456']);
    $broken = Records::make('category', ['sortOrder' => 'first']);

    SyncApi::pushOk($user, ['category' => [$clash, $broken]]);

    $line = LogFile::line('Expenses push with rejected records.');

    expect($line['level_name'] ?? null)->toBe('WARNING')
        ->and(LogFile::context($line)['records'] ?? null)->toBe(['category' => ['rejected' => 2]])
        ->and(LogFile::context($line)['rejected'] ?? null)->toBe([
            ['resource' => 'category', 'id' => $clash->id, 'issues' => ['/name unique']],
            ['resource' => 'category', 'id' => $broken->id, 'issues' => ['/sortOrder type']],
        ]);

    expect(LogFile::contents())->not->toContain(SECRET_NAME, '#123456', 'first', $taken->id);
});

it('warns of a push refused as a whole', function (): void {
    $user = SyncApi::userWithProfile();

    SyncApi::assertError(SyncApi::pushRaw($user, Json::encode(['records' => ['category' => [Records::make('category', ['id' => 'nope', 'name' => SECRET_NAME])]]])), 422, 'contract_violation');
    SyncApi::assertError(SyncApi::push($user, ['currency' => [Records::make('category')]]), 422, 'resource_not_writable');

    $lines = array_values(array_filter(LogFile::lines(), static fn (array $line): bool => ($line['message'] ?? null) === 'Expenses push refused.'));

    expect($lines)->toHaveCount(2)
        ->and(LogFile::context($lines[0]))->toMatchArray(['user' => $user->uuid, 'code' => 'contract_violation', 'issues' => ['/records/category/0/id format']])
        ->and(LogFile::context($lines[1]))->toMatchArray(['code' => 'resource_not_writable', 'resource' => 'currency'])
        ->and(LogFile::contents())->not->toContain(SECRET_NAME);
});

it('writes to the configured channel', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'expenses-own-log-');
    Config::set('logging.channels.expenses_own', ['driver' => 'single', 'path' => $path, 'level' => 'debug', 'formatter' => JsonFormatter::class]);
    Config::set('expenses.logging.channel', 'expenses_own');

    SyncApi::pushOk(SyncApi::userWithProfile(), ['category' => [Records::make('category')]]);

    expect((string) file_get_contents((string) $path))->toContain('"Expenses push."')
        ->and(LogFile::contents())->not->toContain('Expenses push.');
});
