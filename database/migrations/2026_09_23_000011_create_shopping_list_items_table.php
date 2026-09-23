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
        Schema::create(PackageConfig::table('shopping_list_items'), function (Blueprint $table): void {
            Columns::uuid($table, 'id');
            Columns::owner($table);
            Columns::ownedReference($table, 'shopping_list_id', 'shopping_lists');
            $table->text('name');
            $table->text('normalized_name');
            $table->double('quantity');
            $table->text('unit')->nullable();
            $table->boolean('is_checked');
            $table->integer('sort_order');
            Columns::syncColumns($table);

            $table->primary(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(PackageConfig::table('shopping_list_items'));
    }
};
