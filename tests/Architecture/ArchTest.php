<?php

declare(strict_types=1);

arch()->preset()->php();

arch()->preset()->security();

arch('source files declare strict types')
    ->expect('TamasLabs\LaravelExpenses')
    ->toUseStrictTypes();

arch('no debugging leftovers in the source')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->not->toBeUsed();
