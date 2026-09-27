<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use TamasLabs\LaravelExpenses\Auth\AccountStore;
use TamasLabs\LaravelExpenses\Database\Columns;
use TamasLabs\LaravelExpenses\Support\PackageConfig;

/*
 * The client installations signed in (spec 07, 4.1): a device belongs to one
 * account at a time, an account may have several.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(PackageConfig::table(AccountStore::DEVICES), function (Blueprint $table): void {
            $table->id();
            Columns::uuid($table, 'device_id')->unique();
            Columns::owner($table);
            $table->dateTime('linked_at', 3);
            $table->dateTime('last_seen_at', 3);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(PackageConfig::table(AccountStore::DEVICES));
    }
};
