<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use TamasLabs\LaravelExpenses\Database\Columns;
use TamasLabs\LaravelExpenses\Support\PackageConfig;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(PackageConfig::table('budget_pockets'), function (Blueprint $table): void {
            Columns::uuid($table, 'id');
            Columns::owner($table);
            $table->text('name');
            $table->unsignedTinyInteger('start_day');
            $table->unsignedBigInteger('budget_amount_minor');
            $table->char('color', 7)->nullable();
            Columns::syncColumns($table);

            $table->primary(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(PackageConfig::table('budget_pockets'));
    }
};
