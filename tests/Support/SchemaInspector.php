<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Reads a MySQL schema back from `information_schema`, in compact strings the
 * tests compare against the spec (03, 3.11).
 */
final readonly class SchemaInspector
{
    public function __construct(private string $schema) {}

    public static function current(): self
    {
        return new self(DB::connection()->getDatabaseName());
    }

    /**
     * @return list<string>
     */
    public function tables(): array
    {
        return self::strings(
            'SELECT TABLE_NAME AS v FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = \'BASE TABLE\' ORDER BY TABLE_NAME',
            [$this->schema],
        );
    }

    /**
     * Each column as `type[ null][ <ascii collation>][ generated]`, e.g.
     * `char(36) ascii_bin` or `datetime(3) null`.
     *
     * @return array<string, string>
     */
    public function columns(string $table): array
    {
        $rows = DB::select(
            'SELECT COLUMN_NAME AS name, COLUMN_TYPE AS type, IS_NULLABLE AS nullable,
                    CHARACTER_SET_NAME AS charset, COLLATION_NAME AS collation, EXTRA AS extra
             FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
             ORDER BY ORDINAL_POSITION',
            [$this->schema, $table],
        );

        $columns = [];

        foreach ($rows as $row) {
            \assert(\is_object($row));
            $description = self::string($row, 'type');

            if (self::string($row, 'nullable') === 'YES') {
                $description .= ' null';
            }

            if (self::string($row, 'charset') === 'ascii') {
                $description .= ' '.self::string($row, 'collation');
            }

            if (str_contains(self::string($row, 'extra'), 'GENERATED')) {
                $description .= ' generated';
            }

            $columns[self::string($row, 'name')] = $description;
        }

        return $columns;
    }

    /**
     * Each foreign key as `table(cols) -> parent(cols) DELETE/UPDATE`.
     *
     * @return list<string>
     */
    public function foreignKeys(): array
    {
        return self::strings(
            'SELECT CONCAT(k.TABLE_NAME, \'(\', GROUP_CONCAT(k.COLUMN_NAME ORDER BY k.ORDINAL_POSITION), \') -> \',
                           k.REFERENCED_TABLE_NAME, \'(\', GROUP_CONCAT(k.REFERENCED_COLUMN_NAME ORDER BY k.ORDINAL_POSITION),
                           \') \', r.DELETE_RULE, \'/\', r.UPDATE_RULE) AS v
             FROM information_schema.KEY_COLUMN_USAGE k
             JOIN information_schema.REFERENTIAL_CONSTRAINTS r
               ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
             WHERE k.TABLE_SCHEMA = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL
             GROUP BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.REFERENCED_TABLE_NAME, r.DELETE_RULE, r.UPDATE_RULE
             ORDER BY v',
            [$this->schema],
        );
    }

    /**
     * Each primary key and unique index as `table PRIMARY(cols)` or
     * `table UNIQUE(cols)`.
     *
     * @return list<string>
     */
    public function uniqueKeys(): array
    {
        return self::strings(
            'SELECT CONCAT(TABLE_NAME, \' \', IF(INDEX_NAME = \'PRIMARY\', \'PRIMARY\', \'UNIQUE\'),
                           \'(\', GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX), \')\') AS v
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ? AND NON_UNIQUE = 0
             GROUP BY TABLE_NAME, INDEX_NAME
             ORDER BY v',
            [$this->schema],
        );
    }

    /**
     * The columns of every index of a table, as `col,col`.
     *
     * @return list<string>
     */
    public function indexes(string $table): array
    {
        return self::strings(
            'SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS v
             FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
             GROUP BY INDEX_NAME ORDER BY v',
            [$this->schema, $table],
        );
    }

    /**
     * The names of every index and constraint.
     *
     * @return list<string>
     */
    public function identifiers(): array
    {
        return self::strings(
            'SELECT INDEX_NAME AS v FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ?
             UNION SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = ?',
            [$this->schema, $this->schema],
        );
    }

    /**
     * @param  list<string>  $bindings
     * @return list<string>
     */
    private static function strings(string $sql, array $bindings): array
    {
        return array_values(array_map(
            static function (mixed $row): string {
                \assert(\is_object($row));

                return self::string($row, 'v');
            },
            DB::select($sql, $bindings),
        ));
    }

    private static function string(object $row, string $field): string
    {
        $value = get_object_vars($row)[$field] ?? '';

        return \is_scalar($value) ? (string) $value : '';
    }
}
