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
        Schema::create(PackageConfig::table('product_prices'), function (Blueprint $table): void {
            Columns::uuid($table, 'id');
            Columns::owner($table);
            Columns::ownedReference($table, 'product_id', 'products');
            $table->string('shop_display_name', 80);
            $table->text('normalized_shop');
            $table->unsignedBigInteger('unit_price_minor');
            Columns::uuidReference($table, 'currency_id', 'currencies');
            $table->double('quantity')->nullable();
            $table->string('unit', 20)->nullable();
            $table->date('observed_at');
            $table->string('source', 16);
            Columns::ownedReference($table, 'source_expense_id', 'expenses')->nullable();
            Columns::syncColumns($table);

            $table->primary(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(PackageConfig::table('product_prices'));
    }
};
