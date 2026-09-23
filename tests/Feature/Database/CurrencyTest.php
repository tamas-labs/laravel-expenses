<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use TamasLabs\ExpensesSchema\ExpensesSchema;
use TamasLabs\LaravelExpenses\Database\Currencies;
use TamasLabs\LaravelExpenses\Database\Factories\ExpenseFactory;
use TamasLabs\LaravelExpenses\Database\SyncSequence;
use TamasLabs\LaravelExpenses\Models\Currency;
use TamasLabs\LaravelExpenses\Support\PackageConfig;

// Spec 03, 3.5 and 3.9.

/**
 * The currency schema's examples, in column form.
 *
 * @return list<array<string, mixed>>
 */
function currencyExamplesAsRows(): array
{
    $rows = [];

    foreach ((array) (ExpensesSchema::get('currency')['examples'] ?? []) as $example) {
        assert(is_array($example) && is_string($example['createdAt']) && is_string($example['updatedAt']));

        $rows[] = [
            'id' => $example['id'],
            'code' => $example['code'],
            'name' => $example['name'],
            'symbol' => $example['symbol'],
            'minor_unit' => $example['minorUnit'],
            'created_at' => str_replace(['T', 'Z'], [' ', ''], $example['createdAt']),
            'updated_at' => str_replace(['T', 'Z'], [' ', ''], $example['updatedAt']),
            'deleted_at' => $example['deletedAt'],
        ];
    }

    return $rows;
}

it('seeds exactly the currency examples of the schema', function (): void {
    $rows = DB::table(PackageConfig::table('currencies'))
        ->orderBy('server_seq')
        ->get(['id', 'code', 'name', 'symbol', 'minor_unit', 'created_at', 'updated_at', 'deleted_at'])
        ->map(static fn (object $row): array => (array) $row)
        ->all();

    expect($rows)->toBe(currencyExamplesAsRows());
});

it('keeps the seed constant in line with the schema examples', function (): void {
    $seed = array_map(static fn (array $row): array => [...$row, 'deleted_at' => null], Currencies::SEED);

    expect($seed)->toBe(currencyExamplesAsRows());
});

it('gives the seeded currencies the first sequence numbers', function (): void {
    $sequence = DB::table(PackageConfig::table(SyncSequence::TABLE))->sole();

    expect(Currency::query()->orderBy('server_seq')->pluck('server_seq')->all())->toBe([1, 2, 3])
        ->and((array) $sequence)->toBe(['id' => 1, 'value' => 3, 'pruned_through' => 0]);
});

it('reserves consecutive sequence numbers inside a transaction', function (): void {
    $last = DB::transaction(fn (): int => SyncSequence::reserve(5));

    expect($last)->toBe(8)
        ->and(DB::transaction(fn (): int => SyncSequence::reserve(1)))->toBe(9);
});

it('refuses to reserve sequence numbers outside a transaction', function (): void {
    // RefreshDatabase wraps the test in a transaction; step out of it.
    DB::rollBack();

    try {
        expect(fn (): int => SyncSequence::reserve(1))->toThrow(LogicException::class, 'inside a transaction');
    } finally {
        DB::beginTransaction();
    }
});

it('relates expenses to their currency', function (): void {
    $expense = ExpenseFactory::new()->createOne(['currency_id' => Currencies::EUR]);

    expect($expense->currency->code)->toBe('EUR');
});

it('reads a currency with typed attributes', function (): void {
    $huf = Currency::query()->findOrFail(Currencies::HUF);

    expect($huf->minor_unit)->toBe(2)
        ->and($huf->server_seq)->toBe(1)
        ->and($huf->created_at->format('Y-m-d\TH:i:s.v\Z'))->toBe('2026-01-01T00:00:00.000Z')
        ->and($huf->toArray())->not->toHaveKeys(['server_seq', 'synced_at']);
});
