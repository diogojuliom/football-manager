<?php

namespace Tests\Feature\Careers;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_link_uses_trusted_origin_and_token_is_hashed_single_use_and_bound_to_email(): void
    {
        Notification::fake();
        config(['app.url' => 'https://football.example']);
        $user = User::factory()->create(['email' => 'ana@example.com']);
        $other = User::factory()->create();
        $this->withHeader('Host', 'untrusted.example')->from('/forgot-password')
            ->post('/forgot-password', ['email' => 'ANA@example.com'])->assertSessionHas('status');
        $token = Notification::sent($user, ResetPassword::class)->sole()->token;
        $url = (new ResetPassword($token))->toMail($user)->actionUrl;
        $this->assertStringStartsWith('https://football.example/reset-password/', $url);
        $this->assertNotSame($token, DB::table('password_reset_tokens')->sole()->token);
        $this->get('/reset-password/'.$token.'?email=ana%40example.com')->assertOk();
        $payload = ['token' => $token, 'email' => $other->email, 'password' => 'new-password', 'password_confirmation' => 'new-password'];
        $this->post('/reset-password', $payload)->assertSessionHasErrors('email');
        $payload['email'] = $user->email;
        $this->post('/reset-password', $payload)->assertRedirect(route('login'));
        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->post('/reset-password', $payload)->assertSessionHasErrors('email');
        $this->post('/login', ['email' => $user->email, 'password' => 'new-password'])->assertRedirect(route('careers.index'));
    }

    public function test_expired_tokens_cannot_change_passwords(): void
    {
        $user = User::factory()->create();
        $before = $user->password;
        $token = Password::createToken($user);
        $this->travel(config('auth.passwords.users.expire') + 1)->minutes();
        $this->post('/reset-password', ['token' => $token, 'email' => $user->email,
            'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertSessionHasErrors('email');
        $this->assertSame($before, $user->fresh()->password);
        $this->travelBack();
    }

    public function test_recovery_does_not_reveal_accounts_and_limits_requests(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->from('/forgot-password')->post('/forgot-password', ['email' => $user->email])->assertRedirect('/forgot-password');
        $status = session('status');
        $this->from('/forgot-password')->post('/forgot-password', ['email' => 'missing@example.com'])->assertSessionHas('status', $status);
        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status', $status);
        Notification::assertSentToTimes($user, ResetPassword::class, 1);
        for ($i = 0; $i < 3; $i++) {
            $this->post('/forgot-password', ['email' => 'missing@example.com'])->assertRedirect();
        }
        $this->post('/forgot-password', ['email' => 'missing@example.com'])->assertStatus(429);
    }

    public static function sessionStages(): array
    {
        return ['before first protected request' => [false], 'after protected request' => [true]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('sessionStages')]
    public function test_password_change_invalidates_existing_session(bool $visitCareers): void
    {
        $user = User::factory()->create(['password' => 'old-password']);
        $this->post('/login', ['email' => $user->email, 'password' => 'old-password'])->assertRedirect();
        if ($visitCareers) {
            $this->get('/careers')->assertOk();
        }
        $user->update(['password' => 'new-password']);
        $this->app['auth']->forgetGuards();
        $this->get('/careers')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_invalid_recovery_input_and_query_parameters_render_safely(): void
    {
        $this->from('/forgot-password')->post('/forgot-password', ['email' => ['invalid']])->assertSessionHasErrors('email');
        $this->get('/forgot-password')->assertOk();
        $this->get('/reset-password/invalid?email[]=invalid')->assertOk();
    }
}
