<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
 * The development server's two pages: a start page, and where an opened
 * verification link leads (EXPENSES_EMAIL_VERIFIED_URL).
 */
Route::get('/', static fn (): string => 'Expenses development server. API: /api/expenses');

Route::get('/email-verified', static fn (Request $request): string => $request->query('status') === 'invalid'
    ? 'The verification link is invalid or has expired.'
    : 'The email address is verified.');
