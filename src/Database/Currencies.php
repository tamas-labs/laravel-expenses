<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Database;

/**
 * The currencies the package's migration inserts, with the fixed UUIDs the
 * client knows them by. They match the `currency` schema's examples (a test
 * asserts it).
 *
 * This list is what the `create_currencies_table` migration seeds. A new
 * currency comes with a new migration that inserts it and reserves its
 * `server_seq`, not with a new entry here.
 */
final class Currencies
{
    public const string HUF = '00000000-0000-4000-8000-000000000001';

    public const string EUR = '00000000-0000-4000-8000-000000000002';

    public const string USD = '00000000-0000-4000-8000-000000000003';

    /**
     * @var list<array{id: string, code: string, name: string, symbol: string, minor_unit: int, created_at: string, updated_at: string}>
     */
    public const array SEED = [
        [
            'id' => self::HUF,
            'code' => 'HUF',
            'name' => 'Magyar forint',
            'symbol' => 'Ft',
            'minor_unit' => 2,
            'created_at' => '2026-01-01 00:00:00.000',
            'updated_at' => '2026-01-01 00:00:00.000',
        ],
        [
            'id' => self::EUR,
            'code' => 'EUR',
            'name' => 'Euro',
            'symbol' => '€',
            'minor_unit' => 2,
            'created_at' => '2026-01-01 00:00:00.000',
            'updated_at' => '2026-01-01 00:00:00.000',
        ],
        [
            'id' => self::USD,
            'code' => 'USD',
            'name' => 'US dollár',
            'symbol' => '$',
            'minor_unit' => 2,
            'created_at' => '2026-01-01 00:00:00.000',
            'updated_at' => '2026-01-01 00:00:00.000',
        ],
    ];
}
