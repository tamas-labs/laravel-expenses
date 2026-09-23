<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use TamasLabs\LaravelExpenses\Contract\ContractValidator;
use TamasLabs\LaravelExpenses\Mapping\MappingRejection;
use TamasLabs\LaravelExpenses\Registry\ResourceRegistry;
use TamasLabs\LaravelExpenses\Support\Json;
use TamasLabs\LaravelExpenses\Support\PackageConfig;
use TamasLabs\LaravelExpenses\Tests\Fixtures\UserFactory;
use TamasLabs\LaravelExpenses\Tests\Support\ContractSchema;
use TamasLabs\LaravelExpenses\Tests\Support\RecordStore;

// Spec 04, 3.4: timestamps go out as `Y-m-d\TH:i:s.v\Z`; what comes in is
// converted to UTC and cut to the millisecond.

/**
 * The category example with the given createdAt, stored and sent again:
 * what the client gets back as createdAt.
 */
function timestampSentBack(string $createdAt): string
{
    $category = ContractSchema::example('category');
    $category->createdAt = $createdAt;

    expect(app(ContractValidator::class)->validate('category', $category)->issues)->toBe([]);

    $sent = Json::decode(RecordStore::roundTrip('category', $category, UserFactory::new()->createOne()));

    return $sent instanceof stdClass && is_string($sent->createdAt) ? $sent->createdAt : '';
}

it('normalises an incoming timestamp', function (string $incoming, string $sent): void {
    expect(timestampSentBack($incoming))->toBe($sent);
})->with([
    'the client\'s form' => ['2026-09-14T08:30:00.123Z', '2026-09-14T08:30:00.123Z'],
    'an offset' => ['2026-09-14T10:30:00.123+02:00', '2026-09-14T08:30:00.123Z'],
    'an offset across midnight' => ['2026-09-15T01:30:00.000+02:00', '2026-09-14T23:30:00.000Z'],
    'a negative offset' => ['2026-09-14T03:30:00.123-05:00', '2026-09-14T08:30:00.123Z'],
    'no fraction' => ['2026-09-14T08:30:00Z', '2026-09-14T08:30:00.000Z'],
    'a short fraction' => ['2026-09-14T08:30:00.5Z', '2026-09-14T08:30:00.500Z'],
    'microseconds' => ['2026-09-14T08:30:00.123456Z', '2026-09-14T08:30:00.123Z'],
    'lowercase t and z' => ['2026-09-14t08:30:00.123z', '2026-09-14T08:30:00.123Z'],
]);

it('cuts .9995 to .999 instead of rounding to the next second', function (): void {
    $user = UserFactory::new()->createOne();
    $category = ContractSchema::example('category');
    $category->createdAt = '2026-09-14T08:30:59.9995Z';

    $sent = RecordStore::roundTrip('category', $category, $user);

    expect($sent)->toContain('"createdAt":"2026-09-14T08:30:59.999Z"')
        ->and(DB::table(PackageConfig::table('categories'))->where('user_id', $user->getKey())->value('created_at'))
        ->toBe('2026-09-14 08:30:59.999');
});

it('rejects a leap second the contract lets through', function (): void {
    $category = ContractSchema::example('category');
    $category->createdAt = '2026-12-31T23:59:60Z';

    expect(app(ContractValidator::class)->validate('category', $category)->valid)->toBeTrue();

    $rejection = null;

    try {
        app(ResourceRegistry::class)->mapper('category')->toAttributes($category);
    } catch (MappingRejection $caught) {
        $rejection = $caught;
    }

    expect($rejection)->toBeInstanceOf(MappingRejection::class)
        ->and($rejection?->issue->path)->toBe('/createdAt')
        ->and($rejection?->issue->keyword)->toBe('format');
});

describe('in an app running in Europe/Budapest', function (): void {
    beforeEach(function (): void {
        config()->set('app.timezone', 'Europe/Budapest');
        date_default_timezone_set('Europe/Budapest');
    });

    afterEach(function (): void {
        date_default_timezone_set('UTC');
    });

    it('still sends UTC with a Z', function (string $incoming, string $sent): void {
        expect(timestampSentBack($incoming))->toBe($sent);
    })->with([
        'the client\'s form' => ['2026-09-14T08:30:00.123Z', '2026-09-14T08:30:00.123Z'],
        'the local offset' => ['2026-09-14T10:30:00.123+02:00', '2026-09-14T08:30:00.123Z'],
        'winter time' => ['2026-01-14T08:30:00.000Z', '2026-01-14T08:30:00.000Z'],
    ]);
});
