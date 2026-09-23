<?php

declare(strict_types=1);

use TamasLabs\ExpensesSchema\ExpensesSchema;
use TamasLabs\LaravelExpenses\Contract\ContractValidator;
use TamasLabs\LaravelExpenses\Support\Json;

it('only references documents under the contract prefix', function (): void {
    $refs = [];

    foreach (ExpensesSchema::manifest()['schemas'] as $relative) {
        preg_match_all('/"\$ref"\s*:\s*"([^"]*)"/', (string) file_get_contents(dirname(ExpensesSchema::directory()).'/'.$relative), $matches);
        $refs = [...$refs, ...$matches[1]];
    }

    // Relative refs resolve against the documents' own `$id`s, i.e. under
    // ExpensesSchema::BASE_URI, which the validator maps to the package on disk.
    expect($refs)->not->toBeEmpty()
        ->and(preg_grep('#^[a-z][a-z0-9+.-]*:#i', $refs))->toBe([]);
});

it('validates every document without touching the network', function (): void {
    $wrappers = array_intersect(['http', 'https'], stream_get_wrappers());

    // A fresh instance, so nothing is served from another test's compilation.
    $validator = new ContractValidator;

    array_map(stream_wrapper_unregister(...), $wrappers);

    try {
        foreach ([...ExpensesSchema::resources(), ...ExpensesSchema::protocol()] as $name) {
            $isResource = in_array($name, ExpensesSchema::resources(), true);
            $document = Json::decode((string) file_get_contents(ExpensesSchema::path($isResource ? $name : 'protocol/'.$name)));
            assert($document instanceof stdClass);

            $validator->validateAgainst($isResource ? ExpensesSchema::id($name) : ExpensesSchema::protocolId($name), is_array($document->examples ?? null) ? $document->examples[0] ?? new stdClass : new stdClass);
        }
    } finally {
        array_map(stream_wrapper_restore(...), $wrappers);
    }

    expect(stream_get_wrappers())->toContain(...$wrappers);
});
