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
     * to one of its rows. The model must use the HasExpensesProfile and
     * Sanctum's HasApiTokens traits, and implement MustVerifyEmail.
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
         * The URI prefix of the endpoints: {prefix}/sync/…, {prefix}/auth/…
         * and {prefix}/me.
         */
        'prefix' => env('EXPENSES_ROUTE_PREFIX', 'api/expenses'),

        /*
         * The middleware in front of the endpoints. Keep `expenses.contract`:
         * it checks the client's contract version and tells which resources
         * the client knows. Authentication (a Sanctum token with the
         * `expenses:access` ability) is added by the package itself, not here.
         */
        'middleware' => ['api', 'expenses.contract'],

    ],

    'auth' => [

        /*
         * How long an access token and a refresh token are valid, in minutes.
         * Every refresh hands out a new refresh token, so a client that syncs
         * at least once per refresh_ttl stays signed in.
         */
        'access_ttl' => 60,
        'refresh_ttl' => 60 * 24 * 90,

        /*
         * The link in the password reset mail: an app deep link or a page of
         * the host, with the {token} and {email} placeholders. Required.
         */
        'password_reset_url' => env('EXPENSES_PASSWORD_RESET_URL'),

        /*
         * Where the email verification link leads once it is opened; an
         * invalid or expired link gets `status=invalid` appended. Required.
         */
        'email_verified_url' => env('EXPENSES_EMAIL_VERIFIED_URL'),

        /*
         * Whether the sync and profile changes wait for a verified email
         * address (403 email_not_verified until then).
         */
        'require_verified_email' => false,

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
