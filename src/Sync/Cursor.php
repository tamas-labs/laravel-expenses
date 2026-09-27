<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Sync;

use LogicException;
use Stringable;

/**
 * A pull position: every change up to this `server_seq` has been delivered.
 *
 * To the client it is an opaque string it only stores and sends back. The
 * `s:<server_seq>` form is the server's own business and may change.
 */
final readonly class Cursor implements Stringable
{
    private const string PATTERN = '/\As:(0|[1-9][0-9]*)\z/';

    private function __construct(public int $seq) {}

    /**
     * The position before the first change: a pull from here gets everything.
     */
    public static function start(): self
    {
        return new self(0);
    }

    /**
     * The position after the given `server_seq`.
     *
     * @throws LogicException When the number is negative.
     */
    public static function at(int $seq): self
    {
        return $seq >= 0 ? new self($seq) : throw new LogicException(sprintf('A cursor cannot point before the start; got %d.', $seq));
    }

    /**
     * The cursor a client sent back; none (or an empty one) starts from the
     * beginning.
     *
     * @throws ProtocolError When the value is not a cursor the server issues.
     */
    public static function parse(mixed $value): self
    {
        if ($value === null || $value === '') {
            return self::start();
        }

        if (! \is_string($value) || preg_match(self::PATTERN, $value, $matches) !== 1) {
            throw ProtocolError::cursorMalformed();
        }

        $seq = filter_var($matches[1], FILTER_VALIDATE_INT);

        return \is_int($seq) ? new self($seq) : throw ProtocolError::cursorMalformed();
    }

    /**
     * Whether a pull from here would miss tombstones pruned up to the given
     * `server_seq` (spec 06, 4.6). The start misses nothing: a client with no
     * data has no deletions to learn about.
     */
    public function isOlderThan(int $prunedThrough): bool
    {
        return $this->seq > 0 && $this->seq < $prunedThrough;
    }

    public function __toString(): string
    {
        return 's:'.$this->seq;
    }
}
