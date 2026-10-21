<?php

namespace Tests\Feature\Careers;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_login_and_logout(): void
    {
        $this->get('/register')->assertOk();
        $this->post('/register', ['name' => 'Ana', 'email' => 'ANA@example.com', 'password' => 'secret-password', 'password_confirmation' => 'secret-password'])->assertRedirect(route('careers.index'));
        $user = User::sole();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('ana@example.com', $user->email);
        $this->assertTrue(Hash::check('secret-password', $user->password));
        $this->get('/login')->assertRedirect('/careers');
        $this->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->post('/login', ['email' => 'ANA@example.com', 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('/login', ['email' => 'ANA@example.com', 'password' => 'secret-password'])->assertRedirect(route('careers.index'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_registration_rejects_duplicate_emails_and_unconfirmed_passwords(): void
    {
        User::factory()->create(['email' => 'ana@example.com']);
        $this->post('/register', ['name' => 'Ana', 'email' => 'ANA@example.com', 'password' => 'secret-password', 'password_confirmation' => 'different'])->assertSessionHasErrors(['email', 'password']);
        $this->assertGuest();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_malformed_email_input_is_a_validation_error(): void
    {
        $this->post('/login', ['email' => ['invalid'], 'password' => 'secret-password'])->assertSessionHasErrors('email');
        $this->post('/register', ['name' => 'Ana', 'email' => ['invalid'], 'password' => 'secret-password', 'password_confirmation' => 'secret-password'])->assertSessionHasErrors('email');
    }

    public function test_session_mutations_require_csrf_tokens(): void
    {
        $this->app->instance('env', 'local');
        $this->post('/register', ['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'secret-password', 'password_confirmation' => 'secret-password'])->assertStatus(419);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_login_attempts_are_rate_limited(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->post('/login', ['email' => 'unknown@example.com', 'password' => 'wrong'])->assertSessionHasErrors();
        }
        $this->post('/login', ['email' => 'unknown@example.com', 'password' => 'wrong'])->assertStatus(429);
    }
}
