<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request of the package an id (spec 08, 3.6): the client's
 * `X-Request-Id` when it is a UUID, a new one otherwise. The response sends
 * it back, and every log line written during the request carries it, so a
 * failure the client saw can be found in the server's log.
 *
 * The id goes into Laravel's Context, which the log adds to each line on any
 * channel and which Octane empties between requests.
 */
final class AssignRequestId
{
    public const string HEADER = 'X-Request-Id';

    /**
     * The key of the id in the Context and in the log lines.
     */
    public const string CONTEXT_KEY = 'requestId';

    /**
     * The request attribute holding the id.
     */
    private const string ATTRIBUTE = 'expenses.request_id';

    /**
     * The id of the request; a new one when it did not pass this middleware.
     */
    public static function of(Request $request): string
    {
        $id = $request->attributes->get(self::ATTRIBUTE);

        return \is_string($id) ? $id : self::assign($request);
    }

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $id = self::assign($request);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }

    private static function assign(Request $request): string
    {
        $sent = $request->headers->get(self::HEADER);
        // Lowercase, as the contract writes every UUID.
        $id = \is_string($sent) && Str::isUuid($sent) ? strtolower($sent) : Str::uuid()->toString();

        $request->attributes->set(self::ATTRIBUTE, $id);
        Context::add(self::CONTEXT_KEY, $id);

        return $id;
    }
}
