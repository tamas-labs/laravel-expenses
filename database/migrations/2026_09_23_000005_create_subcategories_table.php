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
        Schema::create(PackageConfig::table('subcategories'), function (Blueprint $table): void {
            Columns::uuid($table, 'id');
            Columns::owner($table);
            Columns::ownedReference($table, 'category_id', 'categories');
            $table->text('name');
            $table->integer('sort_order')->nullable();
            Columns::syncColumns($table);
            Columns::activeKey($table, 'name_key', 'name');

            $table->primary(['user_id', 'id']);
            $table->unique(['user_id', 'category_id', 'name_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(PackageConfig::table('subcategories'));
    }
};
