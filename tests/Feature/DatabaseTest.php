<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('runs against MySQL, not SQLite', function (): void {
    expect(DB::connection()->getDriverName())->toBe('mysql');
});

it('runs against MySQL 8.4 or newer', function (): void {
    $version = DB::connection()->getServerVersion();

    expect(version_compare($version, '8.4', '>='))->toBeTrue("MySQL {$version} is older than 8.4");
});

// The contract timestamps carry milliseconds; spec 03 stores them in
// `datetime(3)` columns (not `timestamp`, which ends in 2038).
it('keeps milliseconds in a datetime(3) column', function (): void {
    Schema::create('expenses_precision_probe', function (Blueprint $table): void {
        $table->dateTime('at', 3);
    });

    try {
        DB::table('expenses_precision_probe')->insert(['at' => '2026-09-23 10:15:30.123']);

        expect(DB::table('expenses_precision_probe')->value('at'))->toBe('2026-09-23 10:15:30.123');
    } finally {
        Schema::drop('expenses_precision_probe');
    }
});
