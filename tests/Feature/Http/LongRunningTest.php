<?php

declare(strict_types=1);

use TamasLabs\LaravelExpenses\Auth\Accounts;
use TamasLabs\LaravelExpenses\Auth\DeviceSessions;
use TamasLabs\LaravelExpenses\Contract\ContractValidator;
use TamasLabs\LaravelExpenses\Http\Middleware\AssignRequestId;
use TamasLabs\LaravelExpenses\Registry\ResourceRegistry;
use TamasLabs\LaravelExpenses\Rules\DomainValidator;
use TamasLabs\LaravelExpenses\Sync\PullHandler;
use TamasLabs\LaravelExpenses\Sync\PushHandler;
use TamasLabs\LaravelExpenses\Sync\SyncStore;
use TamasLabs\LaravelExpenses\Tests\Support\AuthApi;
use TamasLabs\LaravelExpenses\Tests\Support\LogFile;
use TamasLabs\LaravelExpenses\Tests\Support\Records;
use TamasLabs\LaravelExpenses\Tests\Support\SyncApi;

// Spec 08, 3.9 and 4: under Octane one application serves request after
// request. The singletons hold no one's data: two users one after the other
// on the same instance see only their own.

const SINGLETONS = [
    ContractValidator::class, ResourceRegistry::class, DomainValidator::class, SyncStore::class, PushHandler::class,
    PullHandler::class, Accounts::class, DeviceSessions::class,
];

beforeEach(function (): void {
    SyncApi::startSequenceAt(1_000_000);
    LogFile::capture();
});

afterEach(function (): void {
    LogFile::forget();
});

it('lets nothing of one user\'s request through to the next user\'s', function (): void {
    $anna = AuthApi::registerOk(['displayName' => 'Anna']);
    $bela = AuthApi::registerOk(['displayName' => 'Béla', 'deviceId' => AuthApi::OTHER_DEVICE]);
    $instances = array_map(app(...), SINGLETONS);

    // Anna pushes a seed category and a record Béla's push will refer to.
    $seed = Records::make('category', ['id' => '00000000-0000-4000-8100-000000000001', 'name' => 'Élelmiszer']);
    $annas = AuthApi::send('POST', 'expenses.sync.push', ['records' => ['category' => [$seed, Records::make('category')]]], AuthApi::accessToken($anna));
    $annas->assertOk();

    // Béla, next on the same instance: Anna's category does not exist for him.
    $expense = Records::make('expense', ['mainCategoryId' => $seed->id]);
    $belas = AuthApi::send('POST', 'expenses.sync.push', ['records' => ['expense' => [$expense]]], AuthApi::accessToken($bela));
    $results = AuthApi::results(AuthApi::ok($belas, 200, 'protocol/push-response'));

    expect(SyncApi::issueKeys(SyncApi::result($results, 'expense')))->toBe(['/mainCategoryId reference']);

    $page = AuthApi::ok(AuthApi::send('GET', 'expenses.sync.pull', token: AuthApi::accessToken($bela)), 200, 'protocol/pull-response');
    $records = SyncApi::records($page);

    expect(SyncApi::group($records, 'category'))->toBe([])
        ->and(array_column(SyncApi::group($records, 'user'), 'id'))->toBe([AuthApi::record($bela)->id]);

    // The same services served both, and the log tells the two apart.
    expect(array_map(app(...), SINGLETONS))->toBe($instances)
        ->and($belas->headers->get(AssignRequestId::HEADER))->not->toBe($annas->headers->get(AssignRequestId::HEADER));

    $pushes = array_values(array_filter(LogFile::lines(), static fn (array $line): bool => \is_string($line['message'] ?? null) && str_starts_with($line['message'], 'Expenses push')));

    expect(array_map(static fn (array $line): mixed => LogFile::context($line)['user'] ?? null, $pushes))->toBe([AuthApi::record($anna)->id, AuthApi::record($bela)->id])
        ->and(array_map(static fn (array $line): mixed => LogFile::extra($line)[AssignRequestId::CONTEXT_KEY] ?? null, $pushes))
        ->toBe([$annas->headers->get(AssignRequestId::HEADER), $belas->headers->get(AssignRequestId::HEADER)]);
});
