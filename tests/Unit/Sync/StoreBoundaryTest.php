<?php

declare(strict_types=1);

// Spec 06, 5.4 and 8/4: only the SyncStore queries the synced tables, so every
// query of them is filtered by the owner in one place. Pest's arch DSL tells
// which classes use which, not who starts a query, so the source is scanned
// for the calls that do.

const MODELS_NAMESPACE = 'TamasLabs\\LaravelExpenses\\Models\\';

/**
 * The files that may query: the store, and the sequence counter it uses.
 */
const QUERYING_FILES = ['src/Sync/SyncStore.php', 'src/Database/SyncSequence.php'];

/**
 * The DB facade's calls that run or start a query (not `transaction()`).
 */
const DB_QUERY_METHODS = [
    'table', 'query', 'select', 'selectOne', 'scalar', 'cursor', 'insert', 'update', 'delete',
    'statement', 'affectingStatement', 'unprepared', 'raw',
];

/**
 * Instance calls that query on their own: a model saving, deleting or
 * reloading itself, or opening a query.
 */
const INSTANCE_QUERY_METHODS = [
    'save', 'saveQuietly', 'saveOrFail', 'update', 'updateQuietly', 'delete', 'deleteQuietly', 'forceDelete',
    'refresh', 'fresh', 'load', 'loadMissing', 'touch',
    'newQuery', 'newModelQuery', 'newQueryWithoutScopes', 'newQueryWithoutRelationships',
];

/**
 * The calls in a PHP source that query synced models or the database:
 * a static call on a model class or on a class held in a variable, a query
 * through the DB facade, or a model instance querying.
 *
 * @return list<string> `line: call`
 */
function queryCalls(string $source): array
{
    $tokens = array_values(array_filter(
        PhpToken::tokenize($source),
        static fn (PhpToken $token): bool => ! $token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
    ));
    $imports = [];
    $calls = [];

    foreach ($tokens as $index => $token) {
        // `use A\B\C;` or `use A\B\C as D;` at the top of the file.
        if ($token->is(T_USE) && ($tokens[$index + 1] ?? null)?->is([T_NAME_QUALIFIED, T_STRING]) === true) {
            $name = $tokens[$index + 1]->text;
            $alias = ($tokens[$index + 2] ?? null)?->is(T_AS) === true ? ($tokens[$index + 3]->text ?? '') : substr((string) strrchr('\\'.$name, '\\'), 1);
            $imports[$alias] = $name;
        }

        $next = $tokens[$index + 1] ?? null;
        $opens = ($tokens[$index + 2] ?? null)?->text === '(';

        if ($next === null || ! $next->is(T_STRING) || ! $opens) {
            continue;
        }

        $method = $next->text;

        if ($token->is(T_DOUBLE_COLON)) {
            $target = $tokens[$index - 1] ?? null;
            $class = $target === null ? '' : ltrim($imports[$target->text] ?? $target->text, '\\');

            $queries = match (true) {
                $target === null => false,
                $target->is(T_VARIABLE), $target->text === ')' => true,
                in_array($target->text, ['self', 'static', 'parent'], true) => false,
                $class === 'Illuminate\\Support\\Facades\\DB' => in_array($method, DB_QUERY_METHODS, true),
                default => str_starts_with($class, MODELS_NAMESPACE),
            };
        } else {
            $queries = $token->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR]) && in_array($method, INSTANCE_QUERY_METHODS, true);
        }

        if ($queries) {
            $calls[] = "{$next->line}: {$method}()";
        }
    }

    return $calls;
}

it('finds the calls that query', function (): void {
    $source = <<<'PHP'
        <?php
        use Illuminate\Support\Facades\DB;
        use TamasLabs\LaravelExpenses\Models\Category;
        use TamasLabs\LaravelExpenses\Models\Currency as Money;
        Category::query();
        Money::where('id', 1);
        $model::query();
        ($this->model())::whereKey(1);
        DB::table('categories');
        $row->save();
        $row?->fresh();
        DB::transaction(fn () => 1);
        DB::afterCommit(fn () => 1);
        Category::class;
        $row::class;
        self::query();
        $list->push(1);
        Str::of('x');
        PHP;

    expect(queryCalls($source))->toBe([
        '5: query()', '6: where()', '7: query()', '8: whereKey()', '9: table()', '10: save()', '11: fresh()',
    ]);
});

it('lets only the SyncStore query the synced tables', function (): void {
    $root = dirname(__DIR__, 3);
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/src', FilesystemIterator::SKIP_DOTS));
    $found = [];
    $scanned = 0;

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $path = substr($file->getPathname(), strlen($root) + 1);
        $calls = queryCalls((string) file_get_contents($file->getPathname()));
        $scanned++;

        if (in_array($path, QUERYING_FILES, true)) {
            expect($calls)->not->toBe([], "{$path} is expected to query.");

            continue;
        }

        foreach ($calls as $call) {
            $found[] = "{$path}:{$call}";
        }
    }

    expect($scanned)->toBeGreaterThan(50)
        ->and($found)->toBe([]);
});
