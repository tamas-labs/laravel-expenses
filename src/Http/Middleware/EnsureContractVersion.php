<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use TamasLabs\LaravelExpenses\Contract\ContractVersion;
use TamasLabs\LaravelExpenses\Support\Json;

/**
 * Rejects requests from clients on an incompatible contract version, and tells
 * every client — on every response — which version the server speaks.
 *
 * The client's version stays on the request ({@see self::clientVersion()}):
 * an older client neither gets nor may push the resources it does not know.
 *
 * Alias: `expenses.contract`.
 */
final class EnsureContractVersion
{
    /**
     * The request attribute holding the client's {@see ContractVersion}.
     */
    public const string CLIENT_VERSION = 'expenses.contract_version';

    /**
     * The contract version the client sent; the server's own when the request
     * did not pass this middleware.
     */
    public static function clientVersion(Request $request): ContractVersion
    {
        $version = $request->attributes->get(self::CLIENT_VERSION);

        return $version instanceof ContractVersion ? $version : ContractVersion::current();
    }

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $server = ContractVersion::current();

        $response = $this->reject($request, $server) ?? $next($request);
        $response->headers->set(ContractVersion::HEADER, (string) $server);

        return $response;
    }

    private function reject(Request $request, ContractVersion $server): ?JsonResponse
    {
        $header = $request->headers->get(ContractVersion::HEADER);

        if ($header === null || $header === '') {
            return $this->error('contract_version_missing', sprintf('The %s header is missing.', ContractVersion::HEADER));
        }

        try {
            $client = ContractVersion::parse($header);
        } catch (InvalidArgumentException) {
            return $this->error('contract_version_malformed', sprintf('The %s header must be <major>.<minor>.', ContractVersion::HEADER));
        }

        if (! $server->accepts($client)) {
            return $this->error('contract_version_unsupported', sprintf('Contract version %s is not supported.', $client), [
                'serverVersion' => (string) $server,
            ]);
        }

        $request->attributes->set(self::CLIENT_VERSION, $client);

        return null;
    }

    /**
     * @param  array<string, string>  $extra
     */
    private function error(string $code, string $message, array $extra = []): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message, ...$extra]], 400, [], Json::ENCODE_FLAGS);
    }
}
