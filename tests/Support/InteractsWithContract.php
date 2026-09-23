<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Tests\Support;

use JsonSerializable;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Response;
use TamasLabs\ExpensesSchema\ExpensesSchema;
use TamasLabs\LaravelExpenses\Contract\ContractIssue;
use TamasLabs\LaravelExpenses\Contract\ContractValidator;

/**
 * Contract assertions for the package's own tests. Pest files call them
 * statically (`TestCase::assertMatchesContract('expense', $payload)`): PHPStan
 * cannot type a custom `expect()->extend()` expectation.
 */
trait InteractsWithContract
{
    /**
     * The schema `$id` of a fixture-style target: a resource name (`expense`)
     * or a protocol document with a `protocol/` prefix (`protocol/error`).
     */
    public static function contractSchemaId(string $target): string
    {
        return str_starts_with($target, 'protocol/')
            ? ExpensesSchema::protocolId(substr($target, \strlen('protocol/')))
            : ExpensesSchema::id($target);
    }

    /**
     * Asserts a payload matches one contract document.
     *
     * A response or a string is validated as raw JSON, an array or
     * `JsonSerializable` in its wire form, anything else as already-decoded JSON.
     */
    public static function assertMatchesContract(string $target, mixed $payload, string $message = ''): void
    {
        $validator = app(ContractValidator::class);
        $schemaId = self::contractSchemaId($target);

        if ($payload instanceof Response) {
            $payload = (string) $payload->getContent();
        }

        $result = match (true) {
            \is_string($payload) => $validator->validateJsonAgainst($schemaId, $payload),
            \is_array($payload), $payload instanceof JsonSerializable => $validator->validatePayloadAgainst($schemaId, $payload),
            default => $validator->validateAgainst($schemaId, $payload),
        };

        $issues = implode("\n", array_map(
            static fn (ContractIssue $issue): string => "  {$issue->path} [{$issue->keyword}] {$issue->message}",
            $result->issues,
        ));

        Assert::assertTrue($result->valid, trim(($message !== '' ? $message."\n" : '')."The payload does not match {$target}:\n{$issues}"));
    }
}
