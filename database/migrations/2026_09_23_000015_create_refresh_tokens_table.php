<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use TamasLabs\LaravelExpenses\Auth\AccountStore;
use TamasLabs\LaravelExpenses\Database\Columns;
use TamasLabs\LaravelExpenses\Support\PackageConfig;

/*
 * The refresh tokens (spec 07, 4.2), stored as SHA-256 hashes only. A used
 * token stays until it expires: presenting it again reveals a theft.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(PackageConfig::table(AccountStore::REFRESH_TOKENS), function (Blueprint $table): void {
            $table->id();
            Columns::owner($table);
            Columns::uuid($table, 'device_id');
            // One sign-in's chain of tokens; a rotation keeps it.
            Columns::uuid($table, 'family')->index();
            $table->char('token_hash', 64)->charset('ascii')->collation('ascii_bin')->unique();
            $table->dateTime('expires_at', 3);
            $table->dateTime('used_at', 3)->nullable();

            $table->index(['user_id', 'device_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(PackageConfig::table(AccountStore::REFRESH_TOKENS));
    }
};
