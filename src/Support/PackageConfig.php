<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

/**
 * Typed, validated access to the `expenses.*` settings.
 */
final class PackageConfig
{
    /**
     * The longest table prefix whose index and foreign key names still fit
     * MySQL's 64 characters (the longest generated one is 57; a test pins it).
     */
    public const int MAX_TABLE_PREFIX_LENGTH = 7;

    /**
     * The host's user model (`expenses.user_model`).
     *
     * @return class-string<Model>
     *
     * @throws InvalidArgumentException When the setting names no Eloquent model.
     */
    public static function userModel(): string
    {
        $model = Config::string('expenses.user_model');

        if (! is_subclass_of($model, Model::class)) {
            throw new InvalidArgumentException(sprintf(
                'The expenses.user_model setting must name an Eloquent model; "%s" is not one.',
                $model,
            ));
        }

        return $model;
    }

    /**
     * The table of the host's user model.
     */
    public static function usersTable(): string
    {
        $model = self::userModel();

        return (new $model)->getTable();
    }

    /**
     * @throws InvalidArgumentException When the prefix is too long or has characters other than letters, digits and underscores.
     */
    public static function tablePrefix(): string
    {
        $prefix = Config::string('expenses.database.table_prefix', '');

        if (preg_match('/\A[A-Za-z0-9_]{0,'.self::MAX_TABLE_PREFIX_LENGTH.'}\z/', $prefix) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'The expenses.database.table_prefix setting must be at most %d letters, digits or underscores; "%s" is not.',
                self::MAX_TABLE_PREFIX_LENGTH,
                $prefix,
            ));
        }

        return $prefix;
    }

    /**
     * The name of one of the package's tables, with the configured prefix.
     */
    public static function table(string $name): string
    {
        return self::tablePrefix().$name;
    }
}
