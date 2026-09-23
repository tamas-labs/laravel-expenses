<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Contract;

/**
 * One violation in a payload — the PHP mirror of the TS `ExpensesValidationIssue`
 * and of the contract's `protocol/common#/$defs/issue`.
 *
 * `path`, `keyword` and `params` are stable and a client may branch on them;
 * `message` is informative English text only.
 */
final readonly class ContractIssue
{
    /**
     * @param  string  $path  JSON Pointer into the payload; the root is `/`.
     * @param  string  $keyword  The failed JSON Schema keyword, or a domain rule name.
     * @param  string  $message  Informative English text.
     * @param  array<string, string|int|float|bool|null>  $params  Machine-readable rule parameters; empty for schema errors.
     */
    public function __construct(
        public string $path,
        public string $keyword,
        public string $message,
        public array $params = [],
    ) {}

    /**
     * The wire shape; `params` is left out when empty, so schema errors look
     * exactly like the TS side's.
     *
     * @return array{path: string, keyword: string, message: string, params?: array<string, string|int|float|bool|null>}
     */
    public function toArray(): array
    {
        $issue = ['path' => $this->path, 'keyword' => $this->keyword, 'message' => $this->message];

        if ($this->params !== []) {
            $issue['params'] = $this->params;
        }

        return $issue;
    }
}
