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

    /**
     * The URI prefix of the sync endpoints (`expenses.routes.prefix`).
     */
    public static function routePrefix(): string
    {
        return Config::string('expenses.routes.prefix');
    }

    /**
     * The middleware in front of the sync endpoints (`expenses.routes.middleware`).
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException When the setting is not a list of middleware names.
     */
    public static function routeMiddleware(): array
    {
        $middleware = Config::array('expenses.routes.middleware');

        if (! array_is_list($middleware) || array_filter($middleware, static fn (mixed $name): bool => ! \is_string($name) || $name === '') !== []) {
            throw new InvalidArgumentException('The expenses.routes.middleware setting must be a list of middleware names.');
        }

        /** @var list<string> $middleware */
        return $middleware;
    }

    /**
     * The most records one push may carry (`expenses.sync.push_max_records`).
     */
    public static function pushMaxRecords(): int
    {
        return self::positiveInt('expenses.sync.push_max_records');
    }

    /**
     * The page size of a pull that asks for none (`expenses.sync.pull_default_limit`).
     */
    public static function pullDefaultLimit(): int
    {
        return min(self::positiveInt('expenses.sync.pull_default_limit'), self::pullMaxLimit());
    }

    /**
     * The largest page size a pull may ask for (`expenses.sync.pull_max_limit`).
     */
    public static function pullMaxLimit(): int
    {
        return self::positiveInt('expenses.sync.pull_max_limit');
    }

    /**
     * @throws InvalidArgumentException When the setting is not a positive integer.
     */
    private static function positiveInt(string $key): int
    {
        $value = Config::integer($key);

        if ($value < 1) {
            throw new InvalidArgumentException(sprintf('The %s setting must be a positive integer; got %d.', $key, $value));
        }

        return $value;
    }
}
