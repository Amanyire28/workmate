<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends one plain email to check that the server's mail settings work, for
 * example after setting MAIL_* in .env. Shows which mailer and sender are in
 * use, never the password.
 */
class SendTestEmail extends Command
{
    protected $signature = 'workmate:mail-test {email : Address to send the test email to}';

    protected $description = 'Send a test email to check the mail settings';

    public function handle(): int
    {
        $to = $this->argument('email');

        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->error('That is not a valid email address.');

            return 1;
        }

        $mailer = config('mail.default');
        $from = config('mail.from.address');

        $this->line("Mailer: {$mailer}");
        $this->line('From: '.($from ?: '(not set)'));

        if (! $from) {
            $this->error('Set MAIL_FROM_ADDRESS in .env, then run: php artisan config:clear');

            return 1;
        }

        if (in_array($mailer, ['log', 'array'], true)) {
            $this->warn("The {$mailer} mailer doesn't send real emails; the message only goes to ".($mailer === 'log' ? 'storage/logs.' : 'memory.'));
        }

        try {
            Mail::raw(
                "This is a test email from WorkMate.\n\nIf you can read this, the server can send emails, so account confirmation and password reset emails will arrive too.",
                function (Message $message) use ($to) {
                    $message->to($to)->subject('WorkMate test email');
                }
            );
        } catch (Throwable $e) {
            $this->error('Sending failed: '.$e->getMessage());

            return 1;
        }

        $this->info("Sent to {$to}. Check the inbox (and the spam folder).");

        return 0;
    }
}
