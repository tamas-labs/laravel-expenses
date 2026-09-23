<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Contract;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\JsonPointer;

/**
 * Flattens opis' error tree into the issue list Ajv (`allErrors`) reports.
 *
 * - Wrapper errors (`properties`, `items`, `$ref`, a schema's multi-keyword
 *   node) only carry their children; the failed keywords are the leaves.
 * - `anyOf` / `oneOf` report their branches' errors, then themselves, as Ajv does.
 * - `required` and `additionalProperties` yield one issue per property, on the
 *   parent object's path, with Ajv's wording.
 *
 * @internal
 */
final class OpisErrorMapper
{
    /**
     * Combinators Ajv reports after the errors of their branches.
     */
    private const array SELF_REPORTING_WRAPPERS = ['anyOf', 'oneOf'];

    private readonly ErrorFormatter $formatter;

    public function __construct()
    {
        $this->formatter = new ErrorFormatter;
    }

    /**
     * @param  int  $limit  Upper bound on the issues returned.
     * @return list<ContractIssue>
     */
    public function map(ValidationError $error, int $limit): array
    {
        $issues = [];

        $this->collect($error, $issues, $limit);

        return $issues;
    }

    /**
     * @param  list<ContractIssue>  $issues
     */
    private function collect(ValidationError $error, array &$issues, int $limit): void
    {
        if (\count($issues) >= $limit) {
            return;
        }

        /** @var list<ValidationError> $subErrors */
        $subErrors = $error->subErrors();

        if ($subErrors !== []) {
            foreach ($subErrors as $subError) {
                $this->collect($subError, $issues, $limit);
            }

            if (\in_array($error->keyword(), self::SELF_REPORTING_WRAPPERS, true)) {
                $this->push($issues, $limit, $this->issue($error));
            }

            return;
        }

        $path = JsonPointer::pathToString($error->data()->fullPath());

        if ($error->keyword() === 'required') {
            foreach ($this->names($error, 'missing') as $name) {
                $this->push($issues, $limit, new ContractIssue($path, 'required', "must have required property '{$name}'"));
            }

            return;
        }

        if ($error->keyword() === 'additionalProperties') {
            foreach ($this->undeclared($error) as $ignored) {
                $this->push($issues, $limit, new ContractIssue($path, 'additionalProperties', 'must NOT have additional properties'));
            }

            return;
        }

        $this->push($issues, $limit, $this->issue($error));
    }

    private function issue(ValidationError $error): ContractIssue
    {
        return new ContractIssue(
            JsonPointer::pathToString($error->data()->fullPath()),
            $error->keyword(),
            $this->formatter->formatErrorMessage($error),
        );
    }

    /**
     * The properties an `additionalProperties: false` error names, minus the
     * declared ones.
     *
     * With `stop_at_first_error` off, opis' `properties` keyword does not mark
     * the object's properties as checked when one of them fails, so
     * `additionalProperties` then lists every property of the object. Ajv
     * never does; the declared ones are dropped here.
     *
     * @return list<string>
     */
    private function undeclared(ValidationError $error): array
    {
        $schema = $error->schema()->info()->data();
        $declared = \is_object($schema) && isset($schema->properties) && \is_object($schema->properties)
            ? array_map(strval(...), array_keys(get_object_vars($schema->properties)))
            : [];

        return array_values(array_diff($this->names($error, 'properties'), $declared));
    }

    /**
     * @return list<string>
     */
    private function names(ValidationError $error, string $arg): array
    {
        $names = $error->args()[$arg] ?? [];

        return \is_array($names) ? array_values(array_map(strval(...), array_filter($names, is_scalar(...)))) : [];
    }

    /**
     * @param  list<ContractIssue>  $issues
     */
    private function push(array &$issues, int $limit, ContractIssue $issue): void
    {
        if (\count($issues) < $limit) {
            $issues[] = $issue;
        }
    }
}
