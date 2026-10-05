<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use TamasLabs\LaravelExpenses\Http\HttpError;
use TamasLabs\LaravelExpenses\Support\PackageConfig;

/**
 * Refuses a body larger than `expenses.http.max_body_kb` with 413
 * `payload_too_large` (spec 08, 3.2).
 *
 * The `Content-Length` decides before the body is read. A request without
 * one (chunked transfer) is judged by the body it sent.
 */
final class EnsurePayloadSize
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $max = PackageConfig::maxBodyBytes();

        if ($this->size($request) > $max) {
            return HttpError::payloadTooLarge($max);
        }

        return $next($request);
    }

    private function size(Request $request): int
    {
        $length = $request->headers->get('Content-Length');

        if (\is_string($length) && preg_match('/\A[0-9]{1,18}\z/', $length) === 1) {
            return (int) $length;
        }

        return \strlen($request->getContent());
    }
}
