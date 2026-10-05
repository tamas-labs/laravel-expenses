<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use JsonException;
use LogicException;
use TamasLabs\LaravelExpenses\Auth\Accounts;
use TamasLabs\LaravelExpenses\Auth\AuthError;
use TamasLabs\LaravelExpenses\Auth\AuthRequest;
use TamasLabs\LaravelExpenses\Auth\DeleteAccount;
use TamasLabs\LaravelExpenses\Auth\Throttle;
use TamasLabs\LaravelExpenses\Auth\UserModel;
use TamasLabs\LaravelExpenses\Contract\ContractIssue;
use TamasLabs\LaravelExpenses\Contract\ContractViolation;
use TamasLabs\LaravelExpenses\Support\Json;

/**
 * `GET`, `PATCH` and `DELETE {prefix}/me` (spec 07, 5.5 and 5.6).
 */
final class MeController
{
    public function show(Request $request, Accounts $accounts): JsonResponse
    {
        return new JsonResponse($accounts->payload(UserModel::of($request->user())), 200, [], Json::ENCODE_FLAGS);
    }

    /**
     * The body is the full `user` record.
     *
     * @throws AuthError|ContractViolation
     */
    public function update(Request $request, Accounts $accounts): JsonResponse
    {
        try {
            $record = Json::decode($request->getContent());
        } catch (JsonException $exception) {
            throw new ContractViolation('user', [new ContractIssue('/', 'json', $exception->getMessage())]);
        }

        return new JsonResponse($accounts->updateProfile(UserModel::of($request->user()), $record), 200, [], Json::ENCODE_FLAGS);
    }

    /**
     * The password is asked again: a stolen access token alone must not be
     * enough to delete the account. Wrong ones are braked as at the sign-in.
     *
     * @throws AuthError
     */
    public function destroy(Request $request, AuthRequest $parser, DeleteAccount $delete): Response
    {
        $user = UserModel::of($request->user());
        $body = $parser->body($request, 'account-delete-request');
        $key = $user->getKey();
        $key = \is_int($key) || \is_string($key) ? $key : throw new LogicException('The user has no key.');

        Throttle::ensureDeletionAllowed($key);

        if (! Hash::check(AuthRequest::string($body, 'password'), $user->getAuthPassword())) {
            Throttle::deletionFailed($key);

            throw AuthError::invalidCredentials();
        }

        $delete($user);

        return new Response(status: 204);
    }
}
