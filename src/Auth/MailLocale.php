<?php

declare(strict_types=1);

namespace TamasLabs\LaravelExpenses\Auth;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;

/**
 * The language of the account mails (spec 07, 5.8): the first of the
 * request's `Accept-Language` the package has texts for, otherwise the app's
 * default.
 */
final class MailLocale
{
    /**
     * The languages the package ships the notification texts in (`lang/`);
     * English is Laravel's own.
     */
    public const array SUPPORTED = ['hu', 'en'];

    public static function of(Request $request): string
    {
        foreach ($request->getLanguages() as $language) {
            $primary = strtolower(explode('_', str_replace('-', '_', $language))[0]);

            if (\in_array($primary, self::SUPPORTED, true)) {
                return $primary;
            }
        }

        return Config::string('app.locale', 'en');
    }

    /**
     * Runs the sending in the request's language. The app's locale is
     * switched, not the notification's: the host may send notifications of
     * its own from the user model.
     *
     * @template T
     *
     * @param  Closure(): T  $send
     * @return T
     */
    public static function send(Request $request, Closure $send): mixed
    {
        $previous = App::getLocale();
        App::setLocale(self::of($request));

        try {
            return $send();
        } finally {
            App::setLocale($previous);
        }
    }
}
