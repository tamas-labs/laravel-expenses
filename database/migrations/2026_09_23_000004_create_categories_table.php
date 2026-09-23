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
        Schema::create(PackageConfig::table('categories'), function (Blueprint $table): void {
            Columns::uuid($table, 'id');
            Columns::owner($table);
            $table->text('name');
            $table->integer('sort_order')->nullable();
            $table->char('color', 7)->nullable();
            $table->text('icon')->nullable();
            Columns::syncColumns($table);
            Columns::activeKey($table, 'name_key', 'name');

            $table->primary(['user_id', 'id']);
            $table->unique(['user_id', 'name_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(PackageConfig::table('categories'));
    }
};
