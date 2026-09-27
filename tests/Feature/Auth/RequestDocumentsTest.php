<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use TamasLabs\ExpensesSchema\ExpensesSchema;
use TamasLabs\LaravelExpenses\Support\Json;
use TamasLabs\LaravelExpenses\Tests\Support\AuthApi;
use TamasLabs\LaravelExpenses\Tests\Support\ContractSchema;
use TamasLabs\LaravelExpenses\Tests\Support\SyncApi;

// Spec 07, 5: the endpoints check their bodies with the contract's own
// request documents, so its examples pass and its invalid fixtures fail with
// the issues the fixtures name.

/**
 * Request document → the route that takes it, and whether it needs a token.
 */
const AUTH_REQUEST_ROUTES = [
    'register-request' => ['POST', 'expenses.auth.register', false],
    'login-request' => ['POST', 'expenses.auth.login', false],
    'refresh-request' => ['POST', 'expenses.auth.refresh', false],
    'password-forgot-request' => ['POST', 'expenses.auth.password.forgot', false],
    'password-reset-request' => ['POST', 'expenses.auth.password.reset', false],
    'account-delete-request' => ['DELETE', 'expenses.me.destroy', true],
];

beforeEach(function (): void {
    Notification::fake();
});

/**
 * Sends a body to the document's route, with a token of another account
 * when the route needs one.
 *
 * @return TestResponse<Response>
 */
function sendDocument(string $document, stdClass $body): TestResponse
{
    [$method, $route, $protected] = AUTH_REQUEST_ROUTES[$document];
    $token = $protected ? AuthApi::accessToken(AuthApi::registerOk(['email' => 'token-holder@example.com', 'deviceId' => AuthApi::OTHER_DEVICE])) : null;

    return AuthApi::send($method, $route, $body, $token);
}

/**
 * The shared fixtures of the auth request documents.
 *
 * @return array<string, array{string, stdClass}>
 */
function authRequestFixtures(): array
{
    $cases = [];

    foreach (array_keys(AUTH_REQUEST_ROUTES) as $document) {
        foreach (glob(ExpensesSchema::fixturesDirectory()."/protocol/{$document}/*/*.json") ?: [] as $file) {
            $case = Json::decode((string) file_get_contents($file));
            assert($case instanceof stdClass);
            $cases[$document.'/'.basename(dirname($file)).'/'.basename($file)] = [$document, $case];
        }
    }

    return $cases;
}

it('takes every example of the request documents', function (string $document): void {
    for ($index = 0; $index < ContractSchema::exampleCount("protocol/{$document}"); $index++) {
        $response = sendDocument($document, ContractSchema::example("protocol/{$document}", $index));
        $content = (string) $response->getContent();
        $body = $content === '' ? null : Json::decode($content);
        $error = $body instanceof stdClass ? ($body->error ?? null) : null;

        // Any answer but a refused body: the example account may not exist.
        expect($response->status())->toBeLessThan(500)
            ->and($error instanceof stdClass ? $error->code ?? null : null)->not->toBe('validation_failed');
    }
})->with(array_keys(AUTH_REQUEST_ROUTES));

it('has shared fixtures of the auth requests to send', function (): void {
    expect(authRequestFixtures())->not->toBeEmpty();
});

it('decides the shared fixtures as the contract does', function (string $document, stdClass $case): void {
    $data = $case->data;
    assert($data instanceof stdClass);
    $response = sendDocument($document, $data);

    if ($case->valid === true) {
        expect($response->status())->not->toBe(422);

        return;
    }

    $error = SyncApi::assertError($response, 422, 'validation_failed');
    $expected = array_map(static fn (stdClass $issue): string => SyncApi::string($issue->path ?? null).' '.SyncApi::string($issue->keyword ?? null), SyncApi::group($case, 'issues'));

    expect(SyncApi::issueKeys($error))->toEqualCanonicalizing($expected);
})->with(authRequestFixtures());
