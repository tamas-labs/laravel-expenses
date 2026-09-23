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
        Schema::create(PackageConfig::table('products'), function (Blueprint $table): void {
            Columns::uuid($table, 'id');
            Columns::owner($table);
            $table->text('normalized_name');
            $table->string('display_name', 80);
            Columns::ownedReference($table, 'main_category_id', 'categories')->nullable();
            Columns::ownedReference($table, 'sub_category_id', 'subcategories')->nullable();
            $table->string('default_unit', 20)->nullable();
            $table->string('barcode', 13)->charset('ascii')->collation('ascii_bin')->nullable();
            // JSON text, not MySQL's JSON type, which would reorder the keys (spec 03, 3.8).
            $table->longText('off_data')->nullable();
            $table->text('off_category')->nullable();
            $table->dateTime('off_last_synced_at', 3)->nullable();
            $table->text('user_note')->nullable();
            $table->unsignedTinyInteger('user_rating')->nullable();
            $table->string('user_status', 16)->nullable();
            $table->text('preferred_shop')->nullable();
            $table->text('user_tags');
            $table->boolean('purchase_reminder');
            $table->text('custom_fields');
            Columns::syncColumns($table);
            Columns::activeKey($table, 'normalized_name_key', 'normalized_name');
            Columns::activeKey($table, 'barcode_key', 'barcode', plainLength: 13);

            $table->primary(['user_id', 'id']);
            $table->unique(['user_id', 'normalized_name_key']);
            $table->unique(['user_id', 'barcode_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(PackageConfig::table('products'));
    }
};
