<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Mapping;

/**
 * One contract field and the model attribute it maps to.
 *
 * A mapper lists its fields in the order of the schema's `properties`, which
 * is also the key order of the payloads it builds.
 */
final readonly class Field
{
    /**
     * @param  string  $name  The contract property, e.g. `amountMinor`.
     * @param  string  $column  The model attribute, e.g. `amount_minor`; for
     *                          {@see FieldType::Links} the pivot table
     *                          (without the package's prefix).
     */
    private function __construct(
        public string $name,
        public string $column,
        public FieldType $type,
        public bool $nullable = false,
    ) {}

    public static function uuid(string $name, string $column): self
    {
        return new self($name, $column, FieldType::Uuid);
    }

    public static function text(string $name, string $column): self
    {
        return new self($name, $column, FieldType::Text);
    }

    public static function int(string $name, string $column): self
    {
        return new self($name, $column, FieldType::Int);
    }

    public static function float(string $name, string $column): self
    {
        return new self($name, $column, FieldType::Float);
    }

    public static function bool(string $name, string $column): self
    {
        return new self($name, $column, FieldType::Bool);
    }

    public static function date(string $name, string $column): self
    {
        return new self($name, $column, FieldType::Date);
    }

    public static function timestamp(string $name, string $column): self
    {
        return new self($name, $column, FieldType::Timestamp);
    }

    public static function json(string $name, string $column): self
    {
        return new self($name, $column, FieldType::Json);
    }

    /**
     * A list of UUIDs the storage layer keeps in `$pivot` (see {@see MappedRecord::$links}).
     */
    public static function links(string $name, string $pivot): self
    {
        return new self($name, $pivot, FieldType::Links);
    }

    /**
     * The same field, with `null` allowed in both directions.
     */
    public function nullable(): self
    {
        return new self($this->name, $this->column, $this->type, true);
    }
}
