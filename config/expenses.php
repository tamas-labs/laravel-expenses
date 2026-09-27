<?php

declare(strict_types=1);

/*
 * Configuration of the Expenses sync backend.
 *
 * Publish it with `php artisan vendor:publish --tag=expenses-config`. Keys are
 * added by the feature that first reads them, so this file only ever lists
 * settings that do something.
 */

return [

    /*
     * The host application's Eloquent user model. Its table gets the profile
     * columns of the contract's `user` record, and every synced row belongs
     * to one of its rows. The model must use the HasExpensesProfile trait.
     */
    'user_model' => env('EXPENSES_USER_MODEL', 'App\\Models\\User'),

    'database' => [

        /*
         * Prepended to the name of every table the package creates, for when
         * the host already has a table called `categories` or `expenses`.
         * Set it before the first migration and never change it afterwards.
         * At most 7 characters (letters, digits, underscores), so that the
         * generated index and foreign key names stay within MySQL's 64.
         */
        'table_prefix' => env('EXPENSES_TABLE_PREFIX', ''),

    ],

    'routes' => [

        /*
         * The URI prefix of the sync endpoints: POST {prefix}/sync/push and
         * GET {prefix}/sync/pull.
         */
        'prefix' => env('EXPENSES_ROUTE_PREFIX', 'api/expenses'),

        /*
         * The middleware in front of the sync endpoints. Keep
         * `expenses.contract`: it checks the client's contract version and
         * tells which resources the client knows. Authentication is added by
         * the package itself, not here.
         */
        'middleware' => ['api', 'expenses.contract'],

    ],

    'sync' => [

        /*
         * The most records one push may carry; a client splits a larger set
         * of changes into several pushes.
         */
        'push_max_records' => 500,

        /*
         * The records one pull page holds when the client asks for no limit,
         * and the most it may ask for.
         */
        'pull_default_limit' => 500,
        'pull_max_limit' => 1000,

    ],

];
