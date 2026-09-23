<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Contract;

use InvalidArgumentException;
use Stringable;
use TamasLabs\ExpensesSchema\ExpensesSchema;

/**
 * A contract version in `major.minor` form, as the `X-Expenses-Contract`
 * header carries it.
 */
final readonly class ContractVersion implements Stringable
{
    public const string HEADER = 'X-Expenses-Contract';

    public function __construct(
        public int $major,
        public int $minor,
    ) {}

    /**
     * @throws InvalidArgumentException When the value is not `<major>.<minor>`.
     */
    public static function parse(string $version): self
    {
        if (preg_match('/\A(\d+)\.(\d+)\z/', $version, $matches) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a <major>.<minor> contract version.', $version));
        }

        return new self((int) $matches[1], (int) $matches[2]);
    }

    /**
     * The version of the installed `tamas-labs/expenses-schema`.
     */
    public static function current(): self
    {
        return self::parse(ExpensesSchema::VERSION);
    }

    /**
     * Same major, and a client minor no newer than this one: a newer client
     * may send resources or fields this side does not know yet.
     */
    public function accepts(self $client): bool
    {
        return $client->major === $this->major && $client->minor <= $this->minor;
    }

    public function __toString(): string
    {
        return $this->major.'.'.$this->minor;
    }
}
