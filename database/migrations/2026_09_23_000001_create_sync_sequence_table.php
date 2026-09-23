<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use TamasLabs\LaravelExpenses\Database\SyncSequence;
use TamasLabs\LaravelExpenses\Support\PackageConfig;

return new class extends Migration
{
    public function up(): void
    {
        $name = PackageConfig::table(SyncSequence::TABLE);

        Schema::create($name, function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('value');
            // The highest server_seq whose tombstones were pruned (spec 06, 4.6).
            $table->unsignedBigInteger('pruned_through')->default(0);
        });

        DB::table($name)->insert(['id' => 1, 'value' => 0, 'pruned_through' => 0]);
    }

    public function down(): void
    {
        Schema::dropIfExists(PackageConfig::table(SyncSequence::TABLE));
    }
};
