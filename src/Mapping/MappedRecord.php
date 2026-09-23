<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Mapping;

/**
 * An incoming record turned into model attributes, plus the fields that live
 * outside the record's row.
 */
final readonly class MappedRecord
{
    /**
     * @param  array<string, mixed>  $attributes  Model attributes, to `forceFill()`.
     * @param  array<string, list<string>>  $links  The {@see FieldType::Links} fields by
     *                                              contract name (`categoryIds`), for the
     *                                              storage layer to write into the pivot.
     */
    public function __construct(
        public array $attributes,
        public array $links = [],
    ) {}
}
