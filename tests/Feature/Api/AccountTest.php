<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;

class AccountTest extends ApiTestCase
{
    private function registration(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Nakato Sarah',
            'email' => 'Sarah.Nakato@Example.com',
            'phone' => '0772 123 456',
            'password' => 'kampala2026',
            'password_confirmation' => 'kampala2026',
            'accept_terms' => true,
        ], $overrides);
    }

    public function test_registering_creates_a_signed_in_customer_and_sends_a_verification_email()
    {
        Notification::fake();

        $this->fromWebsite()->postJson('/api/v1/auth/register', $this->registration())
            ->assertCreated()
            ->assertJsonPath('data.email', 'sarah.nakato@example.com')
            ->assertJsonPath('data.phone', '+256772123456')
            ->assertJsonPath('data.email_verified', false)
            ->assertJsonPath('data.capabilities', ['customer' => true, 'provider' => false])
            ->assertJsonPath('data.provider_status', 'not_applied')
            ->assertJsonPath('data.roles', []);

        // The session cookie now signs the browser in (fresh guard, as on a new request)
        $this->app['auth']->forgetGuards();
        $this->fromWebsite()->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.name', 'Nakato Sarah');

        $user = User::where('email', 'sarah.nakato@example.com')->sole();
        Notification::assertSentTo($user, VerifyEmail::class);
        $this->assertTrue(Hash::check('kampala2026', $user->password));
        $this->assertSame(1, AuditLog::where('action', 'account.registered')->count());
    }

    public function test_registration_rejects_duplicates_however_they_are_typed()
    {
        User::factory()->create(['email' => 'sarah.nakato@example.com', 'phone' => '+256772123456']);

        $this->fromWebsite()->postJson('/api/v1/auth/register', $this->registration())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'phone']);
    }

    public function test_registration_rejects_weak_passwords_and_invalid_phones()
    {
        $this->fromWebsite()->postJson('/api/v1/auth/register', $this->registration([
            'phone' => '12345',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone', 'password']);

        $this->assertSame(0, User::count());
    }

    public function test_registration_still_succeeds_when_email_cannot_be_sent()
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1]);

        $this->fromWebsite()->postJson('/api/v1/auth/register', $this->registration())->assertCreated();

        // Asking again reports honestly that email isn't working
        $this->fromWebsite()->postJson('/api/v1/me/email/verification-notification')
            ->assertStatus(503)
            ->assertJson(['code' => 'email_unavailable']);
    }

    public function test_signing_in_and_out()
    {
        User::factory()->create(['email' => 'okello@example.com', 'password' => Hash::make('secret-123')]);

        $this->fromWebsite()->postJson('/api/v1/auth/login', ['email' => 'okello@example.com', 'password' => 'wrong-123'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'The email or password is incorrect.');

        $this->fromWebsite()->postJson('/api/v1/auth/login', ['email' => 'OKELLO@example.com ', 'password' => 'secret-123'])
            ->assertOk()
            ->assertJsonPath('data.email', 'okello@example.com');

        $this->fromWebsite()->getJson('/api/v1/me')->assertOk();
        $this->fromWebsite()->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->fromWebsite()->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_suspended_accounts_cannot_sign_in()
    {
        $user = User::factory()->create(['email' => 'okello@example.com', 'password' => Hash::make('secret-123')]);
        $user->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        $this->fromWebsite()->postJson('/api/v1/auth/login', ['email' => 'okello@example.com', 'password' => 'secret-123'])
            ->assertForbidden()
            ->assertJson(['code' => 'account_inactive']);

        $this->assertGuest('web');
    }

    public function test_a_suspension_takes_effect_on_the_next_request()
    {
        $user = $this->signIn(User::factory()->create());
        $user->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        $this->getJson('/api/v1/me')->assertForbidden()->assertJson(['code' => 'account_inactive']);
    }

    public function test_repeated_failed_sign_ins_are_throttled()
    {
        for ($i = 0; $i < 5; $i++) {
            $this->fromWebsite()->postJson('/api/v1/auth/login', ['email' => 'x@example.com', 'password' => 'nope'])->assertStatus(422);
        }

        $this->fromWebsite()->postJson('/api/v1/auth/login', ['email' => 'x@example.com', 'password' => 'nope'])
            ->assertStatus(429)
            ->assertJson(['code' => 'too_many_requests'])
            ->assertHeader('Retry-After');
    }

    public function test_sign_in_needs_the_website_session()
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'x@example.com', 'password' => 'nope'])
            ->assertStatus(400)
            ->assertJson(['code' => 'session_required']);
    }

    public function test_changing_the_password_needs_the_current_one()
    {
        $user = $this->signIn(User::factory()->create(['password' => Hash::make('old-pass-1')]));
        $user->createToken('phone');

        $this->putJson('/api/v1/me/password', ['current_password' => 'wrong', 'password' => 'new-pass-2', 'password_confirmation' => 'new-pass-2'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');

        $this->putJson('/api/v1/me/password', ['current_password' => 'old-pass-1', 'password' => 'new-pass-2', 'password_confirmation' => 'new-pass-2'])
            ->assertOk();

        $this->assertTrue(Hash::check('new-pass-2', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(1, AuditLog::where('action', 'account.password_changed')->count());
    }

    public function test_forgot_password_answers_the_same_for_unknown_emails()
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'okello@example.com']);

        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'okello@example.com'])->assertOk()->json();
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com'])->assertOk()->json();

        $this->assertSame($known, $unknown);
        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;

            return strpos($url, '/reset-password?token=') !== false;
        });
    }

    public function test_resetting_the_password_with_a_valid_link()
    {
        $user = User::factory()->create(['email' => 'okello@example.com']);
        $token = Password::createToken($user);

        $this->postJson('/api/v1/auth/reset-password', ['token' => 'bad', 'email' => 'okello@example.com', 'password' => 'fresh-pass-3', 'password_confirmation' => 'fresh-pass-3'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->postJson('/api/v1/auth/reset-password', ['token' => $token, 'email' => 'okello@example.com', 'password' => 'fresh-pass-3', 'password_confirmation' => 'fresh-pass-3'])
            ->assertOk();

        $this->assertTrue(Hash::check('fresh-pass-3', $user->fresh()->password));
    }

    public function test_the_email_link_confirms_the_address()
    {
        $user = User::factory()->unverified()->create();
        $hash = sha1($user->email);

        $this->get(URL::temporarySignedRoute('api.v1.auth.verify-email', now()->addHour(), ['id' => $user->id, 'hash' => 'wrong']))
            ->assertRedirect('/account?email_verification=invalid');

        $this->get("/api/v1/auth/verify-email/{$user->id}/{$hash}?signature=forged")
            ->assertRedirect('/account?email_verification=expired');

        $this->assertFalse($user->fresh()->hasVerifiedEmail());

        $this->get(URL::temporarySignedRoute('api.v1.auth.verify-email', now()->addHour(), ['id' => $user->id, 'hash' => $hash]))
            ->assertRedirect('/account?email_verification=success');

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_the_confirmation_email_is_worded_for_workmate_and_its_link_lasts_a_day()
    {
        Notification::fake();
        $user = User::factory()->unverified()->create(['name' => 'Nakato Sarah']);

        $user->sendEmailVerificationNotification();

        $mail = null;
        Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user, &$mail) {
            $mail = $notification->toMail($user);

            return true;
        });

        $this->assertSame('Confirm your WorkMate email address', $mail->subject);
        $this->assertSame('Hi Nakato,', $mail->greeting);
        $this->assertSame('Confirm email address', $mail->actionText);

        $html = (string) $mail->render();
        $this->assertStringContainsString('WorkMate by ResNet Systems', $html);
        $this->assertStringContainsString('#1769D2', $html);
        $this->assertStringNotContainsString('Laravel', $html);

        // Still works after 23 hours; expired after 25
        $this->travel(23)->hours();
        $this->get($mail->actionUrl)->assertRedirect('/account?email_verification=success');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());

        $other = User::factory()->unverified()->create();
        $other->sendEmailVerificationNotification();
        $late = null;
        Notification::assertSentTo($other, VerifyEmail::class, function (VerifyEmail $notification) use ($other, &$late) {
            $late = $notification->toMail($other)->actionUrl;

            return true;
        });
        $this->travel(25)->hours();
        $this->get($late)->assertRedirect('/account?email_verification=expired');
        $this->assertFalse($other->fresh()->hasVerifiedEmail());
    }

    public function test_the_mail_test_command_sends_a_message_and_explains_missing_settings()
    {
        config(['mail.from.address' => null]);
        $this->artisan('workmate:mail-test', ['email' => 'owner@example.com'])
            ->expectsOutput('Set MAIL_FROM_ADDRESS in .env, then run: php artisan config:clear')
            ->assertExitCode(1);

        $this->artisan('workmate:mail-test', ['email' => 'not-an-email'])->assertExitCode(1);

        config(['mail.from.address' => 'no-reply@example.com']);
        $this->artisan('workmate:mail-test', ['email' => 'owner@example.com'])
            ->expectsOutput('Sent to owner@example.com. Check the inbox (and the spam folder).')
            ->assertExitCode(0);

        $sent = app('mailer')->getSwiftMailer()->getTransport()->messages();
        $this->assertCount(1, $sent);
        $this->assertSame(['owner@example.com'], array_keys($sent[0]->getTo()));
        $this->assertSame('WorkMate test email', $sent[0]->getSubject());
    }

    public function test_updating_the_profile()
    {
        $user = $this->signIn(User::factory()->create(['email' => 'okello@example.com']));

        $this->patchJson('/api/v1/me', ['name' => 'Okello James', 'phone' => '+256 701 000 111', 'locale' => 'lg', 'email' => 'new@example.com'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Okello James')
            ->assertJsonPath('data.phone', '+256701000111')
            ->assertJsonPath('data.locale', 'lg')
            // Email changes are not accepted here
            ->assertJsonPath('data.email', 'okello@example.com');

        $this->patchJson('/api/v1/me', ['locale' => 'fr'])->assertStatus(422)->assertJsonValidationErrors('locale');

        $log = AuditLog::where('action', 'account.profile_updated')->sole();
        $this->assertSame($user->id, $log->actor_id);
    }
}
