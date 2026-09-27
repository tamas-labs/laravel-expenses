<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Http\Controllers;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use TamasLabs\LaravelExpenses\Http\Middleware\EnsureContractVersion;
use TamasLabs\LaravelExpenses\Support\Json;
use TamasLabs\LaravelExpenses\Support\PackageConfig;
use TamasLabs\LaravelExpenses\Sync\Cursor;
use TamasLabs\LaravelExpenses\Sync\ProtocolError;
use TamasLabs\LaravelExpenses\Sync\PullHandler;

/**
 * `GET {prefix}/sync/pull?cursor=<cursor>&limit=<n>` (spec 06, 3.3).
 */
final class PullController
{
    /**
     * @throws AuthenticationException|ProtocolError
     */
    public function __invoke(Request $request, PullHandler $handler): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof Authenticatable) {
            throw new AuthenticationException;
        }

        $result = $handler->pull(
            $user,
            Cursor::parse($request->query('cursor')),
            self::limit($request->query('limit')),
            EnsureContractVersion::clientVersion($request),
        );

        return new JsonResponse($result, 200, [], Json::ENCODE_FLAGS);
    }

    /**
     * The page size asked for, capped at the configured maximum.
     *
     * @throws ProtocolError When it is not a positive integer.
     */
    private static function limit(mixed $value): int
    {
        $max = PackageConfig::pullMaxLimit();

        if ($value === null || $value === '') {
            return PackageConfig::pullDefaultLimit();
        }

        if (! \is_string($value) || preg_match('/\A[1-9][0-9]*\z/', $value) !== 1) {
            throw ProtocolError::limitMalformed();
        }

        // Longer than any configured maximum, and than an int could hold.
        return \strlen($value) > 9 ? $max : min((int) $value, $max);
    }
}
