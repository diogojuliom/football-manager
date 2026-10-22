<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SessionController extends Controller
{
    public function login(Request $request)
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => Str::lower($request->input('email'))]);
        }
        $data = $request->validate(['email' => $this->emailRules(), 'password' => ['required', 'string']]);
        if (! Auth::attempt($data)) {
            throw ValidationException::withMessages(['email' => 'E-mail ou senha incorretos.']);
        }
        $this->startSession($request);

        return redirect()->intended(route('careers.index'));
    }

    public function register(Request $request)
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => Str::lower($request->input('email'))]);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [...$this->emailRules(), 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);
        $user = User::create($data);
        Auth::login($user);
        $this->startSession($request);

        return redirect()->route('careers.index');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function sendResetLink(Request $request)
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => Str::lower($request->input('email'))]);
        }
        $data = $request->validate(['email' => $this->emailRules()]);
        Password::sendResetLink($data);

        // The same response for missing accounts and throttled tokens avoids account enumeration.
        return back()->with('status', 'Se houver uma conta com esse e-mail, enviaremos um link para redefinir a senha.');
    }

    public function resetForm(Request $request, string $token)
    {
        $email = is_string($request->query('email')) ? $request->query('email') : '';

        return view('auth.reset-password', compact('token', 'email'));
    }

    public function resetPassword(Request $request)
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => Str::lower($request->input('email'))]);
        }
        $data = $request->validate([
            'token' => ['required', 'string'], 'email' => $this->emailRules(),
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);
        $status = Password::reset($data, function (User $user, string $password) {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            event(new PasswordReset($user));
        });
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'Link inválido ou expirado. Solicite um novo link de recuperação.']);
        }

        return redirect()->route('login')->with('status', 'Senha redefinida. Entre com sua nova senha.');
    }

    private function startSession(Request $request): void
    {
        $request->session()->regenerate();
        // Establish the password version immediately, even before the first protected GET.
        $request->session()->put('password_hash_'.Auth::getDefaultDriver(), Auth::user()->getAuthPassword());
    }

    private function emailRules(): array
    {
        // Reject header separators explicitly, including on framework versions whose RFC validator accepts them.
        return ['bail', 'required', 'string', 'max:254', 'not_regex:/[\r\n]/', 'email:rfc,strict'];
    }
}
