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
        Schema::create(PackageConfig::table('budget_pocket_categories'), function (Blueprint $table): void {
            // The pocket's categoryIds. No timestamps or server_seq: the pocket's row
            // carries every change of the set, and the client's pivot has no order.
            Columns::owner($table);
            Columns::ownedReference($table, 'budget_pocket_id', 'budget_pockets');
            Columns::ownedReference($table, 'category_id', 'categories');

            $table->primary(['user_id', 'budget_pocket_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(PackageConfig::table('budget_pocket_categories'));
    }
};
