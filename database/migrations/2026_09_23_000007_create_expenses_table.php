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
        Schema::create(PackageConfig::table('expenses'), function (Blueprint $table): void {
            Columns::uuid($table, 'id');
            Columns::owner($table);
            $table->text('name');
            $table->string('type', 16);
            $table->unsignedBigInteger('amount_minor');
            Columns::uuidReference($table, 'currency_id', 'currencies');
            $table->date('expense_date');
            Columns::ownedReference($table, 'main_category_id', 'categories')->nullable();
            Columns::ownedReference($table, 'sub_category_id', 'subcategories')->nullable();
            Columns::ownedReference($table, 'payment_method_id', 'payment_methods')->nullable();
            $table->string('shop_display_name', 80)->nullable();
            $table->text('normalized_shop')->nullable();
            $table->text('note')->nullable();
            // JSON text, not MySQL's JSON type, which would reorder the keys (spec 03, 3.8).
            $table->longText('items');
            Columns::syncColumns($table);

            $table->primary(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(PackageConfig::table('expenses'));
    }
};
