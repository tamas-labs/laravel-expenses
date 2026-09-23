<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use TamasLabs\LaravelExpenses\Database\Columns;
use TamasLabs\LaravelExpenses\Support\PackageConfig;

/*
 * The contract's `user` record lives on the host's users table: `email` and
 * `name` (displayName) are there already, the rest is added here. The
 * profile's createdAt / updatedAt are client times, kept apart from Laravel's
 * own created_at / updated_at.
 */
return new class extends Migration
{
    private const array COLUMNS = [
        'uuid',
        'default_currency_id',
        'registered_at',
        'profile_created_at',
        'profile_updated_at',
        'server_seq',
    ];

    public function up(): void
    {
        $users = PackageConfig::usersTable();
        $taken = array_values(array_filter(self::COLUMNS, fn (string $column): bool => Schema::hasColumn($users, $column)));

        if ($taken !== []) {
            throw new RuntimeException(sprintf(
                'The %s table already has the column(s) %s, which laravel-expenses adds. Rename them before migrating.',
                $users,
                implode(', ', $taken),
            ));
        }

        Schema::table($users, function (Blueprint $table): void {
            Columns::uuid($table, 'uuid')->nullable();
            Columns::uuidReference($table, 'default_currency_id', 'currencies')->nullable();
            $table->dateTime('registered_at', 3)->nullable();
            $table->dateTime('profile_created_at', 3)->nullable();
            $table->dateTime('profile_updated_at', 3)->nullable();
            $table->unsignedBigInteger('server_seq')->nullable()->unique();
        });

        $this->assignUuids($users);

        Schema::table($users, function (Blueprint $table): void {
            Columns::uuid($table, 'uuid')->change();
            $table->unique('uuid');
        });
    }

    public function down(): void
    {
        Schema::table(PackageConfig::usersTable(), function (Blueprint $table): void {
            $table->dropForeign(['default_currency_id']);
            $table->dropColumn(self::COLUMNS);
        });
    }

    /**
     * Gives the existing users their contract id before the column turns NOT NULL.
     */
    private function assignUuids(string $users): void
    {
        $model = PackageConfig::userModel();
        $key = (new $model)->getKeyName();

        DB::table($users)->whereNull('uuid')->lazyById(500, $key)->each(
            function (object $user) use ($users, $key): void {
                DB::table($users)
                    ->where($key, $user->{$key})
                    ->update(['uuid' => Str::uuid()->toString()]);
            },
        );
    }
};
