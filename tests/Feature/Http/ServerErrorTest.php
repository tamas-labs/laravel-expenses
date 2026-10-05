<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use TamasLabs\LaravelExpenses\Http\Middleware\AssignRequestId;
use TamasLabs\LaravelExpenses\Support\PackageConfig;
use TamasLabs\LaravelExpenses\Tests\Support\LogFile;
use TamasLabs\LaravelExpenses\Tests\Support\Records;
use TamasLabs\LaravelExpenses\Tests\Support\SyncApi;
use TamasLabs\LaravelExpenses\Tests\TestCase;

use function Pest\Laravel\getJson;

// Spec 08, 3.6 and 4: the unexpected failure.

const FAILURE = 'The disk is full at /var/lib/mysql/secret.ibd.';

beforeEach(function (): void {
    SyncApi::startSequenceAt(1_000_000);
    LogFile::capture();

    DB::listen(static function (QueryExecuted $query): void {
        if (str_starts_with($query->sql, 'insert into `'.PackageConfig::table('categories').'`')) {
            throw new RuntimeException(FAILURE);
        }
    });
});

afterEach(function (): void {
    LogFile::forget();
});

it('answers with the server_error envelope and the request id, nothing of the failure', function (bool $debug): void {
    Config::set('app.debug', $debug);
    $id = '5e6f7a8b-9c0d-4e1f-a2b3-c4d5e6f7a8b9';

    $response = SyncApi::push(SyncApi::userWithProfile(), ['category' => [Records::make('category')]], requestId: $id);

    $error = SyncApi::assertError($response, 500, 'server_error');
    TestCase::assertMatchesContract('protocol/error', $response->baseResponse);

    expect($error->requestId ?? null)->toBe($id)
        ->and($response->headers->get(AssignRequestId::HEADER))->toBe($id)
        ->and((string) $response->getContent())->not->toContain('disk', 'RuntimeException', 'secret', '.php');
})->with(['in production' => false, 'with debug on' => true]);

it('still hands the failure to the host, which logs it with the request id', function (): void {
    $id = '5e6f7a8b-9c0d-4e1f-a2b3-c4d5e6f7a8b9';

    SyncApi::push(SyncApi::userWithProfile(), ['category' => [Records::make('category')]], requestId: $id)->assertStatus(500);

    $reported = array_values(array_filter(LogFile::lines(), static fn (array $line): bool => ($line['message'] ?? null) === FAILURE));

    expect($reported)->toHaveCount(1)
        ->and($reported[0]['level_name'] ?? null)->toBe('ERROR')
        ->and(LogFile::extra($reported[0]))->toMatchArray([AssignRequestId::CONTEXT_KEY => $id]);
});

it('reports it once', function (): void {
    Exceptions::fake();

    SyncApi::push(SyncApi::userWithProfile(), ['category' => [Records::make('category')]])->assertStatus(500);

    Exceptions::assertReported(static fn (RuntimeException $exception): bool => $exception->getMessage() === FAILURE);
    Exceptions::assertReportedCount(1);
});

it('leaves the host\'s own routes to the host', function (): void {
    Route::get('host-failure', static fn () => throw new RuntimeException(FAILURE));

    expect(getJson('host-failure')->assertStatus(500)->json('error'))->toBeNull();
});
