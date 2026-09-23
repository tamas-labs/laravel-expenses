<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use TamasLabs\LaravelExpenses\Contract\ContractIssue;
use TamasLabs\LaravelExpenses\Contract\ContractResult;
use TamasLabs\LaravelExpenses\Contract\ContractValidator;
use TamasLabs\LaravelExpenses\Contract\ContractViolation;
use TamasLabs\LaravelExpenses\Tests\TestCase;

use function Pest\Laravel\postJson;

it('throws from an invalid result and stays quiet on a valid one', function (): void {
    (new ContractResult(true))->throwIfInvalid('category');

    $result = app(ContractValidator::class)->validate('category', new stdClass);

    $violation = null;

    try {
        $result->throwIfInvalid('category');
    } catch (ContractViolation $caught) {
        $violation = $caught;
    }

    expect($violation)->toBeInstanceOf(ContractViolation::class);
    assert($violation instanceof ContractViolation);
    expect($violation->resource)->toBe('category')
        ->and($violation->issues)->toBe($result->issues)
        ->and($violation->getMessage())->toBe('The payload does not match the category contract.');
});

it('renders as a 422 protocol/error envelope and is not reported', function (): void {
    Exceptions::fake();

    Route::post('/_test/violation', static function (): never {
        throw new ContractViolation('expense', [new ContractIssue('/', 'required', "must have required property 'name'")]);
    });

    $response = postJson('/_test/violation');

    $response->assertStatus(422)->assertExactJson([
        'error' => [
            'code' => 'contract_violation',
            'message' => 'The payload does not match the expense contract.',
            'resource' => 'expense',
            'issues' => [['path' => '/', 'keyword' => 'required', 'message' => "must have required property 'name'"]],
        ],
    ]);
    TestCase::assertMatchesContract('protocol/error', $response->baseResponse);
    Exceptions::assertNothingReported();
});
