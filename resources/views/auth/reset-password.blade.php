@extends('careers.layout')
@section('title', 'Redefinir senha — Football Manager')
@section('content')
<h1>Redefinir senha</h1>
<form method="post" action="{{ route('password.update') }}">
    @csrf
    <input type="hidden" name="token" value="{{ $token }}">
    <label for="email">E-mail</label>
    <input id="email" name="email" type="email" autocomplete="email" value="{{ is_string(old('email', $email)) ? old('email', $email) : '' }}" required>
    <label for="password">Nova senha (mínimo de 8 caracteres)</label>
    <input id="password" name="password" type="password" autocomplete="new-password" minlength="8" required>
    <label for="password_confirmation">Confirme a senha</label>
    <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
    <button type="submit">Redefinir senha</button>
</form>
@endsection
