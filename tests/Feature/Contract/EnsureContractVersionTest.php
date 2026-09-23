<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use TamasLabs\LaravelExpenses\Contract\ContractIssue;
use TamasLabs\LaravelExpenses\Contract\ContractViolation;
use TamasLabs\LaravelExpenses\Tests\TestCase;

use function Pest\Laravel\getJson;

beforeEach(function (): void {
    Route::middleware('expenses.contract')->group(static function (): void {
        Route::get('/_test/contract', static fn (): array => ['ok' => true]);
        Route::get('/_test/contract/violation', static function (): never {
            throw new ContractViolation('category', [new ContractIssue('/', 'required', "must have required property 'name'")]);
        });
    });
});

it('lets a compatible client through', function (string $version): void {
    getJson('/_test/contract', ['X-Expenses-Contract' => $version])
        ->assertOk()
        ->assertExactJson(['ok' => true])
        ->assertHeader('X-Expenses-Contract', '1.2');
})->with(['1.0', '1.1', '1.2']);

it('rejects a request it cannot serve with a 400 error envelope', function (?string $version, string $code): void {
    $response = getJson('/_test/contract', $version === null ? [] : ['X-Expenses-Contract' => $version]);

    $response->assertStatus(400)
        ->assertHeader('X-Expenses-Contract', '1.2')
        ->assertJsonPath('error.code', $code);
    TestCase::assertMatchesContract('protocol/error', $response->baseResponse);
})->with([
    'missing' => [null, 'contract_version_missing'],
    'empty' => ['', 'contract_version_missing'],
    'malformed' => ['1', 'contract_version_malformed'],
    'malformed with patch' => ['1.2.0', 'contract_version_malformed'],
    'newer minor' => ['1.3', 'contract_version_unsupported'],
    'newer major' => ['2.0', 'contract_version_unsupported'],
    'older major' => ['0.9', 'contract_version_unsupported'],
]);

it('tells an unsupported client the server version', function (): void {
    getJson('/_test/contract', ['X-Expenses-Contract' => '2.0'])->assertExactJson([
        'error' => [
            'code' => 'contract_version_unsupported',
            'message' => 'Contract version 2.0 is not supported.',
            'serverVersion' => '1.2',
        ],
    ]);
});

it('adds the version header to error responses from further down, too', function (): void {
    getJson('/_test/contract/violation', ['X-Expenses-Contract' => '1.2'])
        ->assertStatus(422)
        ->assertHeader('X-Expenses-Contract', '1.2');
});
