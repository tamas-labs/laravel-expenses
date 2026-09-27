<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Http\Controllers\Auth;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use TamasLabs\LaravelExpenses\Auth\DeviceSessions;
use TamasLabs\LaravelExpenses\Auth\UserModel;

/**
 * `POST {prefix}/auth/logout` (spec 07, 5.4): signs out the device of the
 * access token. The client's sign-out and its "Cancel" step both call it.
 */
final class LogoutController
{
    public function __invoke(Request $request, DeviceSessions $sessions): Response
    {
        $user = UserModel::of($request->user());
        $deviceId = DeviceSessions::deviceOf($user);

        if ($deviceId !== null) {
            DB::transaction(static fn () => $sessions->signOut($user, $deviceId));
        }

        return new Response(status: 204);
    }
}
