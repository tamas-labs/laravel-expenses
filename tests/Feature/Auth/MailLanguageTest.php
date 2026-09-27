<?php

declare(strict_types=1);

use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use TamasLabs\LaravelExpenses\Tests\Support\AuthApi;

// Spec 07, 5.8 ("Nyelv"): the mails go out in the request's language. They
// are really sent here, to the array mailer, and read back.

/**
 * The mails sent so far.
 *
 * @return list<Email>
 */
function sentMails(): array
{
    $transport = Mail::mailer('array')->getSymfonyTransport();
    $mails = [];

    if (! $transport instanceof ArrayTransport) {
        throw new RuntimeException('The tests mail through the array transport.');
    }

    foreach ($transport->messages() as $message) {
        $original = $message instanceof SentMessage ? $message->getOriginalMessage() : null;

        if ($original instanceof Email) {
            $mails[] = $original;
        }
    }

    return $mails;
}

function lastMail(): Email
{
    $mails = sentMails();

    return $mails[array_key_last($mails) ?? -1] ?? throw new RuntimeException('No mail was sent.');
}

it('writes the verification mail in the language the request asks for', function (string $languages, string $subject, string $line): void {
    AuthApi::register(headers: ['Accept-Language' => $languages])->assertCreated();

    expect(lastMail()->getSubject())->toBe($subject)
        ->and((string) lastMail()->getHtmlBody())->toContain($line)
        ->and(App::getLocale())->toBe('en');
})->with([
    'Hungarian' => ['hu-HU,hu;q=0.9,en;q=0.8', 'Erősítsd meg az e-mail-címed', 'Az e-mail-címed megerősítéséhez kattints az alábbi gombra.'],
    'English' => ['en-GB,en;q=0.9', 'Verify your email address', 'Please click the button below to verify your email address.'],
    'Hungarian after an unknown one' => ['de-DE,hu;q=0.5', 'Erősítsd meg az e-mail-címed', 'Az e-mail-címed megerősítéséhez'],
    'unknown: the app\'s' => ['de-DE,fr;q=0.5', 'Verify your email address', 'Please click the button below'],
]);

it('writes the password reset mail in Hungarian', function (): void {
    AuthApi::registerOk(['email' => 'anna@example.com']);

    AuthApi::send('POST', 'expenses.auth.password.forgot', ['email' => 'anna@example.com'], headers: ['Accept-Language' => 'hu'])->assertStatus(202);

    expect(lastMail()->getSubject())->toBe('Jelszó visszaállítása')
        ->and((string) lastMail()->getHtmlBody())->toContain('A jelszó-visszaállító link 60 perc múlva lejár.');
});
