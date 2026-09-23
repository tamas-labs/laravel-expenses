<?php

declare(strict_types=1);

use TamasLabs\ExpensesSchema\ExpensesSchema;
use TamasLabs\LaravelExpenses\Contract\ContractVersion;

it('parses a major.minor version', function (): void {
    $version = ContractVersion::parse('1.12');

    expect($version->major)->toBe(1)
        ->and($version->minor)->toBe(12)
        ->and((string) $version)->toBe('1.12');
});

it('rejects a malformed version', function (string $version): void {
    ContractVersion::parse($version);
})->throws(InvalidArgumentException::class)->with([
    'empty' => '',
    'major only' => '1',
    'patch' => '1.2.3',
    'prefix' => 'v1.2',
    'letters' => '1.x',
    'leading space' => ' 1.2',
    'trailing newline' => "1.2\n",
    'negative' => '-1.2',
]);

it('reports the installed schema version as current', function (): void {
    expect((string) ContractVersion::current())->toBe('1.2')->toBe(ExpensesSchema::VERSION);
});

it('accepts the same major with an equal or older minor', function (string $client, bool $accepted): void {
    expect(ContractVersion::parse('1.2')->accepts(ContractVersion::parse($client)))->toBe($accepted);
})->with([
    '1.0' => ['1.0', true],
    '1.1' => ['1.1', true],
    '1.2' => ['1.2', true],
    '1.3' => ['1.3', false],
    '2.0' => ['2.0', false],
    '0.9' => ['0.9', false],
]);
