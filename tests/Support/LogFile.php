<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Tests\Support;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Monolog\Formatter\JsonFormatter;
use PHPUnit\Framework\Assert;

/**
 * Sends the log to a file of JSON lines, as a host's log would get them:
 * the context and what the log adds (the request id) both written.
 */
final class LogFile
{
    public const string CHANNEL = 'expenses_json';

    /**
     * Makes the file channel the default one.
     */
    public static function capture(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'expenses-json-log-');

        Config::set('logging.channels.'.self::CHANNEL, ['driver' => 'single', 'path' => $path, 'level' => 'debug', 'formatter' => JsonFormatter::class]);
        Config::set('logging.default', self::CHANNEL);
        Log::forgetChannel(self::CHANNEL);
    }

    public static function forget(): void
    {
        @unlink(self::path());
    }

    /**
     * The raw file.
     */
    public static function contents(): string
    {
        return (string) @file_get_contents(self::path());
    }

    /**
     * Every line written, decoded.
     *
     * @return list<array<string, mixed>>
     */
    public static function lines(): array
    {
        $lines = [];

        foreach (array_filter(explode("\n", self::contents())) as $line) {
            $decoded = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            $lines[] = self::map($decoded);
        }

        return $lines;
    }

    /**
     * The one line with the message.
     *
     * @return array<string, mixed>
     */
    public static function line(string $message): array
    {
        $found = array_values(array_filter(self::lines(), static fn (array $line): bool => ($line['message'] ?? null) === $message));

        Assert::assertCount(1, $found, "The log has no single line \"{$message}\".");

        return $found[0];
    }

    /**
     * The context the line was logged with.
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    public static function context(array $line): array
    {
        return self::map($line['context'] ?? null);
    }

    /**
     * What the log added to the line: the Context (the request id).
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    public static function extra(array $line): array
    {
        return self::map($line['extra'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private static function map(mixed $value): array
    {
        return \is_array($value) ? array_combine(array_map(strval(...), array_keys($value)), $value) : Assert::fail('Not a map.');
    }

    private static function path(): string
    {
        return Config::string('logging.channels.'.self::CHANNEL.'.path', '');
    }
}
