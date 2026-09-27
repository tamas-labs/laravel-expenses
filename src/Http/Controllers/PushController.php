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

/**
 * `POST {prefix}/sync/push` (spec 06, 3.2). A push also marks the device of
 * the access token as seen (spec 07, 4.1).
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

        // Decoded with objects, never with Laravel's array-based input: an
        // empty `{}` has to stay an object.
        try {
            $body = Json::decode($request->getContent());
        } catch (JsonException $exception) {
            throw new ContractViolation(PushHandler::DOCUMENT, [new ContractIssue('/', 'json', $exception->getMessage())]);
        }

        $result = $handler->push($user, $body, EnsureContractVersion::clientVersion($request));
        $sessions->markSeen($user);

        return new JsonResponse($result, 200, [], Json::ENCODE_FLAGS);
    }
}
