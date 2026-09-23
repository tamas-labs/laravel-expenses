<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Database\Factories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use TamasLabs\LaravelExpenses\Models\SyncModel;
use TamasLabs\LaravelExpenses\Tests\Fixtures\UserFactory;

/**
 * The owner and the bookkeeping columns every synced row needs.
 *
 * Parents (a subcategory's category, a price's product, …) are created for
 * the same user. The server_seq comes from a plain counter, not the sync
 * allocator; it starts above the migration's currencies.
 *
 * @template TModel of SyncModel
 *
 * @extends Factory<TModel>
 */
abstract class SyncModelFactory extends Factory
{
    private static int $serverSeq = 1000;

    public static function nextServerSeq(): int
    {
        return ++self::$serverSeq;
    }

    /**
     * A lowercase v4 UUID, as the client generates.
     */
    public static function uuid(): string
    {
        return Str::uuid()->toString();
    }

    /**
     * Stand-in for the client's normaliser: lowercase ASCII words.
     */
    public static function normalize(string $text): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($text))));
    }

    /**
     * A name unlikely to collide with another factory row of the same user.
     */
    public static function uniqueName(): string
    {
        return Str::ucfirst(fake()->word()).' '.Str::lower(Str::random(8));
    }

    /**
     * A client timestamp: UTC, with milliseconds.
     */
    public static function timestamp(): CarbonImmutable
    {
        return CarbonImmutable::instance(fake()->dateTimeBetween('-1 year', '-1 day'))
            ->utc()
            ->setMicrosecond(fake()->numberBetween(0, 999) * 1000);
    }

    /**
     * The row belongs to the given user (a model or its key).
     */
    public function ownedBy(Model|int|string $user): static
    {
        return $this->state(['user_id' => $user instanceof Model ? $user->getKey() : $user]);
    }

    /**
     * A tombstone: deleted when it was last updated.
     */
    public function deleted(): static
    {
        return $this->state(fn (array $attributes): array => ['deleted_at' => $attributes['updated_at']]);
    }

    /**
     * The id, the owner and the bookkeeping columns, to spread first into a
     * definition: the owner has to come before the parents created for it.
     *
     * @return array<string, mixed>
     */
    protected function syncAttributes(): array
    {
        $createdAt = self::timestamp();

        return [
            'id' => self::uuid(),
            'user_id' => UserFactory::new(),
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'deleted_at' => null,
            'server_seq' => fn (): int => self::nextServerSeq(),
            'synced_at' => CarbonImmutable::now('UTC'),
        ];
    }

    /**
     * A parent row of the same user, for a definition's foreign key.
     *
     * @param  Factory<covariant SyncModel>  $factory
     * @return \Closure(array<string, mixed>): string
     */
    protected static function parent(Factory $factory): \Closure
    {
        return static function (array $attributes) use ($factory): string {
            $parent = $factory->createOne(['user_id' => $attributes['user_id']]);

            return $parent->id;
        };
    }
}
