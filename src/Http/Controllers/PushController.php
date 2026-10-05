<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Http\Controllers;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;
use TamasLabs\LaravelExpenses\Auth\DeviceSessions;
use TamasLabs\LaravelExpenses\Contract\ContractIssue;
use TamasLabs\LaravelExpenses\Contract\ContractViolation;
use TamasLabs\LaravelExpenses\Http\Middleware\EnsureContractVersion;
use TamasLabs\LaravelExpenses\Support\Json;
use TamasLabs\LaravelExpenses\Sync\ProtocolError;
use TamasLabs\LaravelExpenses\Sync\PushHandler;
use TamasLabs\LaravelExpenses\Sync\PushLog;

/**
 * `POST {prefix}/sync/push` (spec 06, 3.2). A push also marks the device of
 * the access token as seen (spec 07, 4.1), and leaves a line in the log,
 * refused or not (spec 08, 3.6).
 */
final class PushController
{
    /**
     * @throws AuthenticationException|ContractViolation|ProtocolError
     */
    public function __invoke(Request $request, PushHandler $handler, DeviceSessions $sessions): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof Authenticatable) {
            throw new AuthenticationException;
        }

        $started = hrtime(true);
        $deviceId = DeviceSessions::deviceOf($user);

        try {
            $result = $handler->push($user, self::body($request), EnsureContractVersion::clientVersion($request));
        } catch (ContractViolation|ProtocolError $error) {
            PushLog::refused($user, $deviceId, $error, self::since($started));

            throw $error;
        }

        $sessions->markSeen($user);
        PushLog::pushed($user, $deviceId, $result, self::since($started));

        return new JsonResponse($result, 200, [], Json::ENCODE_FLAGS);
    }

    /**
     * Decoded with objects, never with Laravel's array-based input: an empty
     * `{}` has to stay an object.
     *
     * @throws ContractViolation When the body is not JSON.
     */
    private static function body(Request $request): mixed
    {
        try {
            return Json::decode($request->getContent());
        } catch (JsonException $exception) {
            throw new ContractViolation(PushHandler::DOCUMENT, [new ContractIssue('/', 'json', $exception->getMessage())]);
        }
    }

    private static function since(int|float $started): float
    {
        return (hrtime(true) - $started) / 1e9;
    }
}
