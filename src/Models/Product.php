<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Models;

use Carbon\CarbonImmutable;
use stdClass;
use TamasLabs\LaravelExpenses\Database\Casts\JsonText;
use TamasLabs\LaravelExpenses\Database\Casts\UtcDateTime;

/**
 * @property string $normalized_name
 * @property string $display_name
 * @property string|null $main_category_id
 * @property string|null $sub_category_id
 * @property string|null $default_unit
 * @property string|null $barcode
 * @property stdClass|null $off_data
 * @property string|null $off_category
 * @property CarbonImmutable|null $off_last_synced_at
 * @property string|null $user_note
 * @property int|null $user_rating
 * @property string|null $user_status
 * @property string|null $preferred_shop
 * @property list<string> $user_tags
 * @property bool $purchase_reminder
 * @property stdClass $custom_fields
 */
final class Product extends SyncModel
{
    protected $table = 'products';

    protected $hidden = ['user_id', 'server_seq', 'synced_at', 'normalized_name_key', 'barcode_key'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'off_data' => JsonText::class,
            'off_last_synced_at' => UtcDateTime::class,
            'user_rating' => 'integer',
            'purchase_reminder' => 'boolean',
            'user_tags' => JsonText::class,
            'custom_fields' => JsonText::class,
        ];
    }
}
