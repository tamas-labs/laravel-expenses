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
        Schema::create(PackageConfig::table('shopping_lists'), function (Blueprint $table): void {
            Columns::uuid($table, 'id');
            Columns::owner($table);
            $table->text('name');
            $table->text('shop')->nullable();
            $table->string('status', 16);
            $table->dateTime('archived_at', 3)->nullable();
            Columns::syncColumns($table);

            $table->primary(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(PackageConfig::table('shopping_lists'));
    }
};
