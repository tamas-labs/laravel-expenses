<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use LogicException;
use TamasLabs\LaravelExpenses\Contract\ContractVersion;
use TamasLabs\LaravelExpenses\Database\Currencies;
use TamasLabs\LaravelExpenses\Support\PackageConfig;
use TamasLabs\LaravelExpenses\Sync\PushHandler;
use TamasLabs\LaravelExpenses\Sync\RecordStatus;
use TamasLabs\LaravelExpenses\Sync\SyncStore;

/**
 * `composer dev:seed` (spec 08, 3.7): the development server's test user,
 * with the client's seed categories, subcategories and payment methods
 * under their fixed seed UUIDs (the client's MULTI-USER.md, 3.c), so the
 * client's merge can be tried against it.
 *
 * Runs any number of times: the user is made once, and the records go in
 * with a push, which leaves unchanged records alone.
 */
final class DatabaseSeeder extends Seeder
{
    public const string EMAIL = 'dev@example.com';

    public const string PASSWORD = 'password';

    /**
     * The client's defaultCategories.ts, in its order.
     */
    public const array CATEGORIES = [
        'Élelmiszer' => ['Napi bevásárlás', 'Pékáru', 'Tejtermék', 'Hús', 'Zöldség-gyümölcs'],
        'Közlekedés' => ['Üzemanyag', 'Tömegközlekedés', 'Parkolás', 'Szerviz'],
        'Lakás' => ['Rezsi', 'Bérleti díj', 'Karbantartás', 'Biztosítás'],
        'Szórakozás' => ['Étterem', 'Mozi', 'Játékok', 'Előfizetések'],
        'Egészség' => ['Gyógyszer', 'Orvos', 'Sport'],
        'Ruházat' => ['Felnőtt', 'Gyerek'],
        'Egyéb' => [],
    ];

    /**
     * The client's defaultPaymentMethods.ts, in its order.
     */
    public const array PAYMENT_METHODS = ['Készpénz', 'Bankkártya', 'Átutalás', 'Online vásárlás'];

    /**
     * Older than any device's seed, so a device's own copy wins.
     */
    private const string SEEDED_AT = '2026-01-01T00:00:00.000Z';

    public function run(PushHandler $push, SyncStore $store): void
    {
        $user = $this->user($store);
        $result = $push->push($user, (object) ['records' => (object) $this->records()], ContractVersion::current());

        foreach ($result->results as $resource => $group) {
            foreach ($group as $record) {
                if ($record->status === RecordStatus::Rejected) {
                    throw new LogicException(sprintf('The seed %s %s was rejected.', $resource, $record->id));
                }
            }
        }

        $this->command->info(sprintf('Seeded %s / %s.', self::EMAIL, self::PASSWORD));
    }

    /**
     * The test user, verified, made as the registration makes one: with the
     * profile and a sequence number, so the pull carries the user record.
     */
    private function user(SyncStore $store): Model&Authenticatable
    {
        $model = PackageConfig::userModel();
        $user = $model::query()->where('email', self::EMAIL)->first() ?? DB::transaction(static function () use ($model, $store): Model {
            $now = CarbonImmutable::now('UTC');
            $user = new $model;

            $user->forceFill([
                'name' => 'Dev',
                'email' => self::EMAIL,
                'password' => Hash::make(self::PASSWORD),
                'email_verified_at' => $now,
                'default_currency_id' => Currencies::HUF,
                'registered_at' => $now,
                'profile_created_at' => $now,
                'profile_updated_at' => $now,
            ]);

            [$seq] = $store->allocateSequence(1);
            $store->saveAccount($user, $seq);

            return $user;
        });

        return $user instanceof Authenticatable ? $user : throw new LogicException('The user model is not authenticatable.');
    }

    /**
     * @return array<string, list<object>>
     */
    private function records(): array
    {
        $records = ['category' => [], 'subcategory' => [], 'payment-method' => []];
        $subcategories = 0;

        foreach (array_keys(self::CATEGORIES) as $position => $name) {
            $id = self::seedId(8100, $position + 1);
            $records['category'][] = self::record($id, ['name' => $name, 'sortOrder' => $position, 'color' => null, 'icon' => null]);

            foreach (self::CATEGORIES[$name] as $subPosition => $subName) {
                $records['subcategory'][] = self::record(self::seedId(8200, ++$subcategories), ['categoryId' => $id, 'name' => $subName, 'sortOrder' => $subPosition]);
            }
        }

        foreach (self::PAYMENT_METHODS as $position => $name) {
            $records['payment-method'][] = self::record(self::seedId(8300, $position + 1), ['name' => $name, 'sortOrder' => $position, 'color' => null, 'icon' => null]);
        }

        return $records;
    }

    /**
     * `00000000-0000-4000-<range>-<number>`, as the client's seedIds.ts.
     */
    public static function seedId(int $range, int $number): string
    {
        return sprintf('00000000-0000-4000-%d-%012d', $range, $number);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private static function record(string $id, array $fields): object
    {
        return (object) ['id' => $id, ...$fields, 'createdAt' => self::SEEDED_AT, 'updatedAt' => self::SEEDED_AT, 'deletedAt' => null];
    }
}
