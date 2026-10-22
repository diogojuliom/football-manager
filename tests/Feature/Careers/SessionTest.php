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

    public function test_login_and_registration_render_native_accessible_forms_and_real_recovery_link(): void
    {
        $this->get('/login')->assertOk()
            ->assertSee('Bem-vindo de volta!')
            ->assertSee('Monte seu elenco.')
            ->assertSee('name="email"', false)
            ->assertSee('name="password"', false)
            ->assertSee('name="remember"', false)
            ->assertSee('data-loading-text="Autenticando..."', false)
            ->assertSee('aria-controls="password"', false)
            ->assertSee(route('password.request'))
            ->assertSee(route('register'));

        $this->get('/register')->assertOk()
            ->assertSee('Criar Nova Conta')
            ->assertSee('name="name"', false)
            ->assertSee('name="password_confirmation"', false)
            ->assertSee('id="password-hints"', false)
            ->assertSee('data-loading-text="Cadastrando..."', false)
            ->assertSee('Maiúscula (opcional)')
            ->assertSee(route('login'));
    }

    public function test_validation_errors_are_announced_and_old_email_is_escaped(): void
    {
        $this->from('/register')->post('/register', [
            'name' => 'Ana', 'email' => 'ana<script>@example.com',
            'password' => 'short', 'password_confirmation' => 'different',
        ])->assertSessionHasErrors(['email', 'password']);

        $this->get('/register')->assertOk()
            ->assertSee('role="alert"', false)
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('id="email-error"', false)
            ->assertSee('ana&lt;script&gt;@example.com', false)
            ->assertDontSee('ana<script>@example.com', false);
    }

    public function test_remember_me_uses_laravel_persistent_login_cookie(): void
    {
        $user = User::factory()->create(['password' => 'secret-password']);
        $response = $this->post('/login', ['email' => $user->email, 'password' => 'secret-password', 'remember' => '1']);

        $response->assertRedirect(route('careers.index'));
        $this->assertAuthenticatedAs($user);
        $this->assertNotEmpty($user->fresh()->remember_token);
        $this->assertNotEmpty(array_filter($response->headers->getCookies(),
            fn ($cookie) => str_starts_with($cookie->getName(), 'remember_')));
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
