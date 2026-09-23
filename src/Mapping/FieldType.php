<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Mapping;

/**
 * How one contract field is carried between the record and the model.
 */
enum FieldType
{
    /** A lowercase UUID string, as is. */
    case Uuid;

    /** A string, byte for byte: no trimming, no Unicode normalisation. */
    case Text;

    /** An integer; the record may carry it as `1.0`. */
    case Int;

    /** A double, written back in its shortest exact form. */
    case Float;

    case Bool;

    /** A `YYYY-MM-DD` calendar day, kept as a string (no time zone to shift it). */
    case Date;

    /** A UTC instant with milliseconds (`Y-m-d\TH:i:s.v\Z` on the wire). */
    case Timestamp;

    /** An array or object tree, stored as JSON text. */
    case Json;

    /** A set of UUIDs kept in a pivot table instead of the record's row. */
    case Links;
}
