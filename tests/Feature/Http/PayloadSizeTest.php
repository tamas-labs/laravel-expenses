<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;
use TamasLabs\LaravelExpenses\Auth\DeviceSessions;
use TamasLabs\LaravelExpenses\Contract\ContractVersion;
use TamasLabs\LaravelExpenses\Models\Category;
use TamasLabs\LaravelExpenses\Support\Json;
use TamasLabs\LaravelExpenses\Tests\Support\AuthApi;
use TamasLabs\LaravelExpenses\Tests\Support\Records;
use TamasLabs\LaravelExpenses\Tests\Support\SyncApi;

use function Pest\Laravel\call;

// Spec 08, 3.2 and 4: the body size limit.

beforeEach(function (): void {
    SyncApi::startSequenceAt(1_000_000);
    Config::set('expenses.http.max_body_kb', 1);
});

/**
 * A push with the given body and, unless null, Content-Length.
 *
 * @return TestResponse<Response>
 */
function pushBody(string $body, ?int $contentLength): TestResponse
{
    Sanctum::actingAs(SyncApi::userWithProfile(), [DeviceSessions::ABILITY]);

    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_EXPENSES_CONTRACT' => (string) ContractVersion::current()];

    if ($contentLength !== null) {
        $server['CONTENT_LENGTH'] = (string) $contentLength;
    }

    return call('POST', route('expenses.sync.push'), [], [], [], $server, $body);
}

/**
 * A push request of about the given size in bytes.
 */
function pushOfSize(int $bytes): string
{
    $category = Records::make('category', ['icon' => '']);
    $category->icon = str_repeat('x', max(0, $bytes - strlen(Json::encode(['records' => ['category' => [$category]]]))));

    return Json::encode(['records' => ['category' => [$category]]]);
}

it('refuses a body its Content-Length says is too large, before reading it', function (): void {
    $error = SyncApi::assertError(pushBody('{"records":{}}', 1025), 413, 'payload_too_large');

    expect($error->message ?? null)->toBe('The request body is larger than 1 KB.')
        ->and(Category::query()->count())->toBe(0);
});

it('refuses a body too large without a Content-Length (chunked)', function (): void {
    $body = pushOfSize(1025);

    expect(strlen($body))->toBe(1025);

    SyncApi::assertError(pushBody($body, null), 413, 'payload_too_large');
    expect(Category::query()->count())->toBe(0);
});

it('takes a body up to the limit', function (?int $contentLength): void {
    $body = pushOfSize(1024);

    pushBody($body, $contentLength === null ? null : strlen($body))->assertOk();

    expect(Category::query()->count())->toBe(1);
})->with(['with Content-Length' => 1, 'chunked' => null]);

it('guards the account endpoints too', function (): void {
    SyncApi::assertError(AuthApi::register(['displayName' => str_repeat('x', 2000)]), 413, 'payload_too_large');
});

it('allows 4 MB by default', function (): void {
    expect(require dirname(__DIR__, 3).'/config/expenses.php')->toHaveKey('http.max_body_kb', 4096);
});
