<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Contract;

use InvalidArgumentException;
use JsonException;
use JsonSerializable;
use Opis\JsonSchema\CompliantValidator;
use RuntimeException;
use TamasLabs\ExpensesSchema\ExpensesSchema;
use TamasLabs\LaravelExpenses\Support\Json;

/**
 * Validates JSON against the `tamas-labs/expenses-schema` contract, reporting
 * the same verdict and issues as the TS side's Ajv-based `validateResource()`.
 *
 * Bound as a singleton: opis caches compiled schemas per instance, so the
 * records of one push batch share a single compilation.
 */
final class ContractValidator
{
    /**
     * Upper bound on the issues one payload reports — enough to show a client
     * everything it got wrong, small enough to cap the work a hostile payload causes.
     */
    public const int MAX_ISSUES = 100;

    private readonly CompliantValidator $validator;

    private readonly OpisErrorMapper $mapper;

    public function __construct()
    {
        // The compliant validator reads the documents as plain JSON Schema
        // 2020-12, like Ajv: opis' own extensions are off, `format` stays on.
        $this->validator = new CompliantValidator(null, self::MAX_ISSUES, false);

        // Every `$id` and cross-file `$ref` resolves from the package on disk.
        $this->validator->resolver()?->registerPrefix(ExpensesSchema::BASE_URI, ExpensesSchema::directory());

        $this->mapper = new OpisErrorMapper;
    }

    /**
     * Validates JSON decoded with objects as `stdClass` (see {@see Json::decode()}).
     *
     * @param  string  $resource  Resource name, e.g. `expense`.
     *
     * @throws InvalidArgumentException When the contract defines no such resource.
     */
    public function validate(string $resource, mixed $decoded): ContractResult
    {
        return $this->validateAgainst(self::resourceId($resource), $decoded);
    }

    /**
     * Decodes and validates a raw body; malformed JSON is a single `json` issue.
     *
     * @param  string  $resource  Resource name, e.g. `expense`.
     *
     * @throws InvalidArgumentException When the contract defines no such resource.
     */
    public function validateJson(string $resource, string $json): ContractResult
    {
        return $this->validateJsonAgainst(self::resourceId($resource), $json);
    }

    /**
     * Validates an outgoing payload in its wire form: encoded the way responses
     * are, then decoded again — so an empty PHP array where the contract wants
     * `{}` fails here, not on the client.
     *
     * @param  string  $resource  Resource name, e.g. `expense`.
     * @param  array<array-key, mixed>|JsonSerializable  $payload
     *
     * @throws InvalidArgumentException When the contract defines no such resource.
     * @throws JsonException When the payload cannot be encoded.
     */
    public function validatePayload(string $resource, array|JsonSerializable $payload): ContractResult
    {
        return $this->validatePayloadAgainst(self::resourceId($resource), $payload);
    }

    /**
     * {@see self::validate()} against a schema `$id`, e.g. `ExpensesSchema::protocolId('push-request')`.
     *
     * @throws InvalidArgumentException When the id is not a document of the contract.
     */
    public function validateAgainst(string $schemaId, mixed $decoded): ContractResult
    {
        if (! str_starts_with($schemaId, ExpensesSchema::BASE_URI)) {
            throw new InvalidArgumentException(sprintf('"%s" is not an Expenses contract schema id.', $schemaId));
        }

        try {
            $error = $this->validator->validate($decoded, $schemaId)->error();
        } catch (RuntimeException $exception) {
            throw new InvalidArgumentException(sprintf('Cannot validate against "%s": %s', $schemaId, $exception->getMessage()), 0, $exception);
        }

        return $error === null
            ? new ContractResult(true)
            : new ContractResult(false, $this->mapper->map($error, self::MAX_ISSUES));
    }

    /**
     * {@see self::validateJson()} against a schema `$id`.
     *
     * @throws InvalidArgumentException When the id is not a document of the contract.
     */
    public function validateJsonAgainst(string $schemaId, string $json): ContractResult
    {
        try {
            $decoded = Json::decode($json);
        } catch (JsonException $exception) {
            return new ContractResult(false, [new ContractIssue('/', 'json', $exception->getMessage())]);
        }

        return $this->validateAgainst($schemaId, $decoded);
    }

    /**
     * {@see self::validatePayload()} against a schema `$id`.
     *
     * @param  array<array-key, mixed>|JsonSerializable  $payload
     *
     * @throws InvalidArgumentException When the id is not a document of the contract.
     * @throws JsonException When the payload cannot be encoded.
     */
    public function validatePayloadAgainst(string $schemaId, array|JsonSerializable $payload): ContractResult
    {
        return $this->validateAgainst($schemaId, Json::decode(Json::encode($payload)));
    }

    /**
     * @throws InvalidArgumentException When the contract defines no such resource.
     */
    private static function resourceId(string $resource): string
    {
        try {
            return ExpensesSchema::id($resource);
        } catch (RuntimeException $exception) {
            throw new InvalidArgumentException($exception->getMessage(), 0, $exception);
        }
    }
}
