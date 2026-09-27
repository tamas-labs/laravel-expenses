<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Tests\Support;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use TamasLabs\LaravelExpenses\Contract\ContractVersion;
use TamasLabs\LaravelExpenses\Database\Currencies;
use TamasLabs\LaravelExpenses\Support\Json;
use TamasLabs\LaravelExpenses\Tests\Fixtures\User;
use TamasLabs\LaravelExpenses\Tests\TestCase;

use function Pest\Laravel\call;

/**
 * Calls the account endpoints (spec 07) the way a client does: JSON bodies,
 * the contract header, the access token as a bearer token.
 */
final class AuthApi
{
    public const string DEVICE = '3f1c2e4d-5a6b-4c7d-8e9f-0a1b2c3d4e5f';

    public const string OTHER_DEVICE = '9a8b7c6d-5e4f-4a3b-8c2d-1e0f9a8b7c6d';

    public const string PASSWORD = 'correct horse battery';

    /**
     * Sends one request.
     *
     * The guards are forgotten first: Laravel keeps the user a guard found
     * for the whole test, so the next token would not be looked at.
     *
     * @param  array<array-key, mixed>|stdClass|string|null  $body  An array or object is encoded, a string sent as it is.
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    public static function send(string $method, string $route, array|stdClass|string|null $body = null, ?string $token = null, array $headers = []): TestResponse
    {
        Auth::forgetGuards();

        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_EXPENSES_CONTRACT' => (string) ContractVersion::current(),
        ];

        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $content = match (true) {
            $body === null => null,
            \is_string($body) => $body,
            default => Json::encode((object) $body),
        };

        return call($method, route($route), [], [], [], $server, $content);
    }

    /**
     * A valid registration body.
     *
     * @param  array<array-key, mixed>  $overrides
     * @return array<array-key, mixed>
     */
    public static function registration(array $overrides = []): array
    {
        return [
            'email' => Str::lower(Str::random(10)).'@example.com',
            'password' => self::PASSWORD,
            'displayName' => 'Anna',
            'deviceId' => self::DEVICE,
            'defaultCurrencyId' => Currencies::HUF,
            ...$overrides,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $overrides
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    public static function register(array $overrides = [], array $headers = []): TestResponse
    {
        return self::send('POST', 'expenses.auth.register', self::registration($overrides), headers: $headers);
    }

    /**
     * Registers, expects a 201 matching `auth-response`, and returns it.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function registerOk(array $overrides = []): stdClass
    {
        return self::ok(self::register($overrides), 201, 'protocol/auth-response');
    }

    /**
     * @return TestResponse<Response>
     */
    public static function login(string $email, string $password = self::PASSWORD, string $deviceId = self::DEVICE): TestResponse
    {
        return self::send('POST', 'expenses.auth.login', ['email' => $email, 'password' => $password, 'deviceId' => $deviceId]);
    }

    /**
     * Signs in, expects a 200 matching `auth-response`, and returns it.
     */
    public static function loginOk(string $email, string $password = self::PASSWORD, string $deviceId = self::DEVICE): stdClass
    {
        return self::ok(self::login($email, $password, $deviceId), 200, 'protocol/auth-response');
    }

    /**
     * @return TestResponse<Response>
     */
    public static function refresh(string $refreshToken, string $deviceId = self::DEVICE): TestResponse
    {
        return self::send('POST', 'expenses.auth.refresh', ['refreshToken' => $refreshToken, 'deviceId' => $deviceId]);
    }

    /**
     * Refreshes, expects a 200 matching `refresh-response`, and returns it.
     */
    public static function refreshOk(string $refreshToken, string $deviceId = self::DEVICE): stdClass
    {
        return self::ok(self::refresh($refreshToken, $deviceId), 200, 'protocol/refresh-response');
    }

    /**
     * @return TestResponse<Response>
     */
    public static function me(string $token): TestResponse
    {
        return self::send('GET', 'expenses.me.show', token: $token);
    }

    /**
     * @param  array<string, mixed>|stdClass|string  $record
     * @return TestResponse<Response>
     */
    public static function patchMe(string $token, array|stdClass|string $record): TestResponse
    {
        return self::send('PATCH', 'expenses.me.update', $record, $token);
    }

    /**
     * @return TestResponse<Response>
     */
    public static function logout(string $token): TestResponse
    {
        return self::send('POST', 'expenses.auth.logout', token: $token);
    }

    /**
     * The access token of an `auth-response` or a `refresh-response`.
     */
    public static function accessToken(stdClass $answer): string
    {
        return SyncApi::string(self::tokens($answer)->accessToken ?? null);
    }

    /**
     * The refresh token of an `auth-response` or a `refresh-response`.
     */
    public static function refreshToken(stdClass $answer): string
    {
        return SyncApi::string(self::tokens($answer)->refreshToken ?? null);
    }

    /**
     * The `user` record of an answer.
     */
    public static function record(stdClass $answer): stdClass
    {
        return ($answer->user ?? null) instanceof stdClass ? $answer->user : Assert::fail('The answer has no user record.');
    }

    /**
     * The account behind an answer's `user` record.
     */
    public static function user(stdClass $answer): User
    {
        return User::query()->where('uuid', self::record($answer)->id ?? null)->firstOrFail();
    }

    /**
     * The `results` of a push answer.
     */
    public static function results(stdClass $answer): stdClass
    {
        return ($answer->results ?? null) instanceof stdClass ? $answer->results : Assert::fail('The answer has no results.');
    }

    /**
     * Expects the status and a body matching the document, and returns the body.
     *
     * @param  TestResponse<Response>  $response
     */
    public static function ok(TestResponse $response, int $status, string $document): stdClass
    {
        $response->assertStatus($status)->assertHeader(ContractVersion::HEADER, (string) ContractVersion::current());
        TestCase::assertMatchesContract($document, $response->baseResponse);

        $body = Json::decode((string) $response->getContent());

        return $body instanceof stdClass ? $body : Assert::fail('The response is not an object.');
    }

    /**
     * The `tokens` of an `auth-response` or a `refresh-response`.
     */
    public static function tokens(stdClass $answer): stdClass
    {
        return ($answer->tokens ?? null) instanceof stdClass ? $answer->tokens : Assert::fail('The answer has no tokens.');
    }
}
