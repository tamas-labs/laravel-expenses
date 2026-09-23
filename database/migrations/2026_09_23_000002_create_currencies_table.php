<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use TamasLabs\LaravelExpenses\Database\Casts\UtcDateTime;
use TamasLabs\LaravelExpenses\Database\Columns;
use TamasLabs\LaravelExpenses\Database\Currencies;
use TamasLabs\LaravelExpenses\Database\SyncSequence;
use TamasLabs\LaravelExpenses\Support\PackageConfig;

return new class extends Migration
{
    public function up(): void
    {
        $name = PackageConfig::table('currencies');

        Schema::create($name, function (Blueprint $table): void {
            Columns::uuid($table, 'id')->primary();
            $table->char('code', 3)->charset('ascii')->collation('ascii_bin')->unique();
            $table->text('name');
            $table->text('symbol');
            $table->unsignedTinyInteger('minor_unit');
            Columns::syncColumns($table, owned: false);
        });

        // Seeded here, not by a seeder: without a currency no expense can be
        // stored. The rows take their server_seq like any write, so the first
        // pull delivers them.
        DB::transaction(function () use ($name): void {
            $seq = SyncSequence::reserve(\count(Currencies::SEED)) - \count(Currencies::SEED);
            $now = CarbonImmutable::now('UTC')->format(UtcDateTime::FORMAT);

            foreach (Currencies::SEED as $currency) {
                DB::table($name)->insert([
                    ...$currency,
                    'deleted_at' => null,
                    'server_seq' => ++$seq,
                    'synced_at' => $now,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(PackageConfig::table('currencies'));
    }
};
